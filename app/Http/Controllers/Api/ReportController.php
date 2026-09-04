<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Services\ReportBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportBuilder $builder,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Report::query()
                ->where('dam_id', $this->builder->damId())
                ->orderByDesc('created_at')
                ->limit(50)
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period' => ['required', 'in:harian,mingguan,bulanan,kustom'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'format' => ['required', 'in:pdf,csv'],
            'sections' => ['array'],
            'sections.*' => ['string'],
        ]);

        $report = $this->builder->build(
            period: $data['period'],
            start: $data['period_start'],
            end: $data['period_end'],
            format: $data['format'],
            sections: $data['sections'] ?? ['ringkasan', 'parameter', 'peringatan'],
            generatedBy: $request->user()?->name,
        );

        return response()->json(['data' => $report], 201);
    }

    public function download(Report $report): StreamedResponse
    {
        abort_unless($report->file_path && Storage::disk('local')->exists($report->file_path), 404);

        return Storage::disk('local')->download(
            $report->file_path,
            str($report->title)->slug().'.'.$report->format,
        );
    }
}
