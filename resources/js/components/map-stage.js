import { postJson } from '../lib/api.js';

/**
 * Digital twin stage.
 *
 * The background is an orthographic render of the dam rather than a tile map,
 * so panning and zooming is plain arithmetic. The render is full-bleed: it
 * covers the whole viewport with the glass chrome floating on top.
 *
 * Only the middle of the render comes from the reference photo; the margins are
 * mirrored terrain that always sits behind the rail, header and summary panel
 * (see tools/build_map_assets.py). Marker percentages therefore refer to that
 * inner region and are mapped through `contentInset`. Markers live outside the
 * transformed layer so their labels stay crisp at any zoom level.
 */
export default function mapStage() {
    return {
        zoom: 1,
        panX: 0,
        panY: 0,
        stageWidth: 0,
        stageHeight: 0,
        dragging: false,
        moved: false,
        captured: false,
        pointerStart: { x: 0, y: 0, panX: 0, panY: 0 },
        hovered: null,
        observer: null,

        /** Marker captions. Off leaves just the pins on the render. */
        showLabels: true,

        /** Marker editing: drag a station to a new spot and store it. */
        editMarkers: false,
        draggingMarker: null,
        markerMoved: false,

        /**
         * Where the glass chrome sits, in CSS pixels — measured from the actual
         * rail / header / summary panel, so any viewport size or panel width
         * works without touching this file. Zooming and "focus this marker"
         * centre on the clear area between them.
         */
        insets: { left: 236, right: 460, top: 104, bottom: 64 },

        get mapConfig() {
            return this.$store.site.boot.map;
        },

        get contentInset() {
            return this.mapConfig.content ?? { left: 0, right: 0, top: 0, bottom: 0 };
        },

        get safeWidth() {
            return Math.max(240, this.stageWidth - this.insets.left - this.insets.right);
        },

        get safeHeight() {
            return Math.max(200, this.stageHeight - this.insets.top - this.insets.bottom);
        },

        /**
         * Full-bleed only pays off on a landscape screen. On a phone held
         * upright, covering a 16:9 render would zoom it three times over and
         * leave the operator looking at a slice of hillside, so there the photo
         * region is fitted instead and the dark app background frames it.
         */
        get coverStage() {
            return this.stageWidth >= 640 && this.stageWidth > this.stageHeight * 0.9;
        },

        /**
         * Two constraints: the render must cover the viewport (no bare edges),
         * and the photo region inside it should sit within the clear area so
         * every marker stays reachable. Taking the larger of the two scales
         * satisfies the first always and the second whenever geometry allows.
         */
        get baseScale() {
            const { width, height } = this.mapConfig;

            if (!this.stageWidth || !this.stageHeight) {
                return 1;
            }

            const inset = this.contentInset;
            const contentW = Math.max(0.05, 1 - inset.left - inset.right);
            const contentH = Math.max(0.05, 1 - inset.top - inset.bottom);

            const cover = Math.max(this.stageWidth / width, this.stageHeight / height);
            const fitPhoto = Math.min(
                this.safeWidth / (width * contentW),
                this.safeHeight / (height * contentH),
            );

            return this.coverStage ? Math.max(cover, fitPhoto) : fitPhoto;
        },

        get contentWidth() {
            return this.mapConfig.width * this.baseScale * this.zoom;
        },

        get contentHeight() {
            return this.mapConfig.height * this.baseScale * this.zoom;
        },

        /** Position that centres the photo region on the clear area. */
        get baseOffsetX() {
            const inset = this.contentInset;
            const centre = inset.left + (1 - inset.left - inset.right) / 2;

            return this.coverClamp(
                this.insets.left + this.safeWidth / 2 - centre * this.contentWidth,
                this.contentWidth,
                this.stageWidth,
            );
        },

        get baseOffsetY() {
            const inset = this.contentInset;
            const centre = inset.top + (1 - inset.top - inset.bottom) / 2;

            return this.coverClamp(
                this.insets.top + this.safeHeight / 2 - centre * this.contentHeight,
                this.contentHeight,
                this.stageHeight,
            );
        },

        /** Keep the render covering the viewport — unless it is deliberately fitted. */
        coverClamp(value, content, viewport) {
            if (!this.coverStage) {
                return value;
            }

            if (content <= viewport) {
                return (viewport - content) / 2;
            }

            return Math.min(0, Math.max(viewport - content, value));
        },

        get offsetX() {
            return this.baseOffsetX + this.panX;
        },

        get offsetY() {
            return this.baseOffsetY + this.panY;
        },

        /** Marker percentage of the photo region -> fraction of the whole render. */
        contentFraction(marker) {
            const inset = this.contentInset;

            return {
                x: inset.left + (marker.x / 100) * (1 - inset.left - inset.right),
                y: inset.top + (marker.y / 100) * (1 - inset.top - inset.bottom),
            };
        },

        initStage() {
            const chrome = (name) => document.querySelector(`[data-chrome="${name}"]`);

            const measure = () => {
                const rect = this.$refs.stage.getBoundingClientRect();
                this.stageWidth = rect.width;
                this.stageHeight = rect.height;

                const gap = parseFloat(getComputedStyle(this.$el).getPropertyValue('--gap')) || 16;
                const rail = chrome('rail')?.getBoundingClientRect();
                const panel = chrome('panel')?.getBoundingClientRect();
                const header = chrome('header')?.getBoundingClientRect();

                // A panel parked off-screen (narrow layouts) simply gives the
                // stage its width back.
                const panelLeft = panel && panel.left < rect.width ? panel.left : rect.width - gap;

                // On phones the rail turns into a bottom bar: then it eats
                // height instead of width.
                const railIsBottomBar = rail ? rail.width > rect.width * 0.6 : false;

                this.insets = {
                    left: (railIsBottomBar ? 0 : (rail ? rail.right : 80)) + gap,
                    right: (rect.width - panelLeft) + gap,
                    top: (header ? header.bottom : 96) + gap * 0.4,
                    bottom: railIsBottomBar ? rect.height - rail.top + gap : gap * 2 + 24,
                };

                this.clampPan();
            };

            try {
                this.showLabels = window.localStorage.getItem('twin.labels') !== '0';
            } catch {
                this.showLabels = true;
            }

            measure();
            this.observer = new ResizeObserver(measure);
            this.observer.observe(this.$refs.stage);

            // The rail and the panel resize on their own (labels appearing,
            // slide-over opening), so watch them too.
            ['rail', 'panel', 'header'].forEach((name) => {
                const element = chrome(name);
                if (element) {
                    this.observer.observe(element);
                }
            });

            this.$watch('$store.viewer.open', (open) => {
                if (!open) {
                    // Settle back to a neutral view when the 360 viewer closes.
                    this.reset();
                }
            });
        },

        destroyStage() {
            this.observer?.disconnect();
        },

        layerStyle() {
            return {
                width: `${this.contentWidth}px`,
                height: `${this.contentHeight}px`,
                transform: `translate3d(${this.offsetX}px, ${this.offsetY}px, 0)`,
            };
        },

        markerStyle(marker) {
            const fraction = this.contentFraction(marker);

            return {
                left: `${this.offsetX + fraction.x * this.contentWidth}px`,
                top: `${this.offsetY + fraction.y * this.contentHeight}px`,
            };
        },

        markerVisible(marker) {
            const fraction = this.contentFraction(marker);
            const x = this.offsetX + fraction.x * this.contentWidth;
            const y = this.offsetY + fraction.y * this.contentHeight;

            return x > this.insets.left - 60
                && x < this.stageWidth - this.insets.right + 90
                && y > this.insets.top - 40
                && y < this.stageHeight - this.insets.bottom + 40;
        },

        /* -------------------------------------------------- pointer input */

        onPointerDown(event) {
            if (event.button !== 0) {
                return;
            }

            this.dragging = true;
            this.moved = false;
            this.captured = false;
            this.pointerStart = { x: event.clientX, y: event.clientY, panX: this.panX, panY: this.panY };
        },

        onPointerMove(event) {
            if (!this.dragging) {
                return;
            }

            const dx = event.clientX - this.pointerStart.x;
            const dy = event.clientY - this.pointerStart.y;

            if (Math.abs(dx) + Math.abs(dy) > 4) {
                this.moved = true;

                // Capture only once a real drag starts: capturing on pointerdown
                // would retarget the following `click` to the stage and markers
                // would never receive it.
                if (!this.captured) {
                    this.$refs.stage.setPointerCapture?.(event.pointerId);
                    this.captured = true;
                }
            }

            this.panX = this.pointerStart.panX + dx;
            this.panY = this.pointerStart.panY + dy;
            this.clampPan();
        },

        onPointerUp(event) {
            this.dragging = false;

            if (this.captured) {
                this.$refs.stage.releasePointerCapture?.(event.pointerId);
                this.captured = false;
            }
        },

        onWheel(event) {
            event.preventDefault();
            const rect = this.$refs.stage.getBoundingClientRect();
            this.zoomAt(
                event.deltaY < 0 ? 1.12 : 1 / 1.12,
                event.clientX - rect.left,
                event.clientY - rect.top,
            );
        },

        /* -------------------------------------------------- zoom controls */

        zoomAt(factor, originX, originY) {
            const { min_zoom: min, max_zoom: max } = this.mapConfig;
            const next = Math.min(max, Math.max(min, this.zoom * factor));

            if (next === this.zoom) {
                return;
            }

            // Keep the point under the cursor fixed while the scale changes.
            const ratio = next / this.zoom;
            const cx = originX ?? this.insets.left + this.safeWidth / 2;
            const cy = originY ?? this.insets.top + this.safeHeight / 2;

            const anchorX = cx - ratio * (cx - this.offsetX);
            const anchorY = cy - ratio * (cy - this.offsetY);

            this.zoom = next;
            this.panX = anchorX - this.baseOffsetX;
            this.panY = anchorY - this.baseOffsetY;
            this.clampPan();
        },

        zoomIn() {
            this.zoomAt(1.25);
        },

        zoomOut() {
            this.zoomAt(1 / 1.25);
        },

        reset() {
            this.zoom = 1;
            this.panX = 0;
            this.panY = 0;
        },

        /** Centre a marker and lean in a little; used when a station is opened. */
        focusMarker(marker, zoom = 1.9) {
            this.zoom = Math.min(this.mapConfig.max_zoom, zoom);

            const fraction = this.contentFraction(marker);

            // Centre on the clear area between the panels, not on the viewport.
            const targetX = this.insets.left + this.safeWidth / 2;
            const targetY = this.insets.top + this.safeHeight / 2;

            this.panX = targetX - fraction.x * this.contentWidth - this.baseOffsetX;
            this.panY = targetY - fraction.y * this.contentHeight - this.baseOffsetY;
            this.clampPan();
        },

        clampPan() {
            const bounds = (base, content, viewport) => {
                if (content <= viewport) {
                    // Fitted stage: allow a little play, but keep it on screen.
                    const slack = this.coverStage ? 0 : Math.max(0, (viewport - content) / 2);

                    return [-slack, slack];
                }

                return [viewport - content - base, -base];
            };

            const [minX, maxX] = bounds(this.baseOffsetX, this.contentWidth, this.stageWidth);
            const [minY, maxY] = bounds(this.baseOffsetY, this.contentHeight, this.stageHeight);

            this.panX = Math.min(maxX, Math.max(minX, this.panX));
            this.panY = Math.min(maxY, Math.max(minY, this.panY));
        },

        /* -------------------------------------------------- interactions */

        selectMarker(marker) {
            if (this.moved || this.editMarkers) {
                return;
            }

            this.focusMarker(marker);
            this.$store.viewer.open360(marker.code);
        },

        /* -------------------------------------------------- marker editing */

        /** Marker captions on or off; the choice sticks per browser. */
        toggleLabels() {
            this.showLabels = !this.showLabels;

            try {
                window.localStorage.setItem('twin.labels', this.showLabels ? '1' : '0');
            } catch {
                // Private mode: the toggle still works, it just does not persist.
            }
        },

        toggleMarkerEditing() {
            this.editMarkers = !this.editMarkers;
            this.draggingMarker = null;
        },

        /** Screen position -> percentage of the photo region. */
        pointToMarker(clientX, clientY) {
            const rect = this.$refs.stage.getBoundingClientRect();
            const inset = this.contentInset;
            const fx = (clientX - rect.left - this.offsetX) / this.contentWidth;
            const fy = (clientY - rect.top - this.offsetY) / this.contentHeight;

            return {
                x: Math.max(0, Math.min(100, ((fx - inset.left) / (1 - inset.left - inset.right)) * 100)),
                y: Math.max(0, Math.min(100, ((fy - inset.top) / (1 - inset.top - inset.bottom)) * 100)),
            };
        },

        startMarkerDrag(marker, event) {
            if (!this.editMarkers || event.button !== 0) {
                return;
            }

            event.stopPropagation();
            event.preventDefault();

            this.draggingMarker = marker.code;
            this.markerMoved = false;
            event.currentTarget.setPointerCapture?.(event.pointerId);
        },

        dragMarker(marker, event) {
            if (this.draggingMarker !== marker.code) {
                return;
            }

            event.stopPropagation();
            this.markerMoved = true;

            const position = this.pointToMarker(event.clientX, event.clientY);
            marker.x = position.x;
            marker.y = position.y;
        },

        async dropMarker(marker, event) {
            if (this.draggingMarker !== marker.code) {
                return;
            }

            event.stopPropagation();
            event.currentTarget.releasePointerCapture?.(event.pointerId);
            this.draggingMarker = null;

            if (!this.markerMoved) {
                return;
            }

            try {
                await postJson(`/api/stations/${marker.code}/position`, {
                    x: Number(marker.x.toFixed(3)),
                    y: Number(marker.y.toFixed(3)),
                });
            } catch (error) {
                console.warn('[marker] gagal menyimpan posisi', error.message);
            }
        },
    };
}
