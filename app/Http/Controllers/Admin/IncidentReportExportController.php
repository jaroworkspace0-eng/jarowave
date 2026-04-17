<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\IncidentReportExportMail;
use App\Models\SosIncidentReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class IncidentReportExportController extends Controller
{
    // ── Shared query builder ──────────────────────────────────────────────────
    private function buildQuery(Request $request)
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to'   => 'required|date|after_or_equal:date_from',
            'status'    => 'nullable|in:pending,reviewed,warned,blocked,dismissed',
            'outcome'   => 'nullable|in:legitimate,misuse',
            'search'    => 'nullable|string|max:100',
        ]);

        $from = $request->date_from . ' 00:00:00';
        $to   = $request->date_to   . ' 23:59:59';

        $query = SosIncidentReport::with([
            'household:id,name,email,phone',
            'reporter:id,name,email,phone',
            'actionedBy:id,name',
        ])
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at', 'desc');

        if ($request->status)  $query->where('status',  $request->status);
        if ($request->outcome) $query->where('outcome', $request->outcome);

        if ($request->search) {
            $s = $request->search;
            $query->whereHas('household', fn($q) =>
                $q->where('name',  'like', "%$s%")
                  ->orWhere('email', 'like', "%$s%")
            );
        }

        return $query->limit(5000);
    }

    private function buildPdfData(Request $request, $reports): array
    {
        return [
            'reports'      => $reports,
            'date_from'    => $request->date_from,
            'date_to'      => $request->date_to,
            'filters'      => [
                'status'  => $request->status,
                'outcome' => $request->outcome,
                'search'  => $request->search,
            ],
            'generated_at' => now()->format('d M Y H:i'),
            'total'        => $reports->count(),
        ];
    }

    private function makePdf(array $data): \Barryvdh\DomPDF\PDF
    {
        return Pdf::loadView('exports.incident-reports-pdf', $data)
            ->setPaper('a4', 'landscape')
            ->setOptions([
                'defaultFont'             => 'DejaVu Sans',
                'isHtml5ParserEnabled'    => true,
                'isRemoteEnabled'         => false,
                'dpi'                     => 96,
                'isFontSubsettingEnabled' => true,
            ]);
    }

    // ── GET /api/admin/incident-reports/export/pdf ────────────────────────────
    public function exportPdf(Request $request)
    {
        $reports  = $this->buildQuery($request)->get();
        $data     = $this->buildPdfData($request, $reports);
        $pdf      = $this->makePdf($data);
        $filename = 'incident-reports-' . $request->date_from . '-to-' . $request->date_to . '.pdf';

        return $pdf->download($filename);
    }

    // ── POST /api/admin/incident-reports/export/email ─────────────────────────
    public function emailExport(Request $request)
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to'   => 'required|date|after_or_equal:date_from',
            'emails'    => 'required|array|min:1|max:10',
            'emails.*'  => 'required|email',
        ]);

        $reports = $this->buildQuery($request)->get();

        if ($reports->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No reports found for the selected date range.',
            ], 422);
        }

        $data     = $this->buildPdfData($request, $reports);
        $pdf      = $this->makePdf($data);
        $filename = 'incident-reports-' . $request->date_from . '-to-' . $request->date_to . '.pdf';

        // Save PDF to storage (queue-safe)
        $path = Storage::path("reports/$filename");
        Storage::put("reports/$filename", $pdf->output());

        $attachments = [[
            'path' => $path,
            'name' => $filename,
            'mime' => 'application/pdf',
        ]];

        foreach ($request->emails as $email) {
            Mail::to($email)->queue(new IncidentReportExportMail(
                dateFrom:    $request->date_from,
                dateTo:      $request->date_to,
                total:       $reports->count(),
                formats:     ['pdf'],
                exportFiles: $attachments,
            ));
        }

        return response()->json([
            'success' => true,
            'message' => 'Report sent to ' . implode(', ', $request->emails) . '.',
            'total'   => $reports->count(),
        ]);
    }
}
