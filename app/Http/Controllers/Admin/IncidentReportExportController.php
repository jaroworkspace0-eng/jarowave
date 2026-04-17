<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\IncidentReportExportMail;
use App\Models\SosIncidentReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use League\Csv\Writer;
use SplTempFileObject;

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

        $query = SosIncidentReport::with([
            'household:id,name,email,phone',
            'reporter:id,name,email',
            'actionedBy:id,name',
            'emergencyAlert:id,created_at,latitude,longitude',
        ])
            ->whereBetween('created_at', [
                $request->date_from . ' 00:00:00',
                $request->date_to   . ' 23:59:59',
            ])
            ->orderBy('created_at', 'desc');

        if ($request->status) {
            $query->where('status', $request->status);
        }

        if ($request->outcome) {
            $query->where('outcome', $request->outcome);
        }

        if ($request->search) {
            $search = $request->search;
            $query->whereHas('household', fn($q) =>
                $q->where('name', 'like', "%$search%")
                  ->orWhere('email', 'like', "%$search%")
            );
        }

        // Hard cap — never export more than 5000 rows at once
        return $query->limit(5000);
    }

    // ── GET /api/admin/incident-reports/export/pdf ────────────────────────────
    public function exportPdf(Request $request)
    {
        $reports = $this->buildQuery($request)->get();

        $data = [
            'reports'   => $reports,
            'date_from' => $request->date_from,
            'date_to'   => $request->date_to,
            'filters'   => [
                'status'  => $request->status,
                'outcome' => $request->outcome,
                'search'  => $request->search,
            ],
            'generated_at' => now()->format('d M Y H:i'),
            'total'        => $reports->count(),
        ];

        $pdf = Pdf::loadView('exports.incident-reports-pdf', $data)
            ->setPaper('a4', 'landscape')
            ->setOptions([
                'defaultFont' => 'sans-serif',
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled'      => false,
            ]);

        $filename = 'incident-reports-' . $request->date_from . '-to-' . $request->date_to . '.pdf';

        return $pdf->download($filename);
    }

    // ── GET /api/admin/incident-reports/export/csv ────────────────────────────
    public function exportCsv(Request $request)
    {
        $reports = $this->buildQuery($request)->get();

        $csv = Writer::createFromFileObject(new SplTempFileObject());

        // Header row
        $csv->insertOne([
            'ID',
            'Date',
            'Household Name',
            'Household Email',
            'Household Phone',
            'Reporter (Patroller)',
            'Reporter Email',
            'Outcome',
            'Misuse Category',
            'Narrative',
            'Arrived At',
            'Departed At',
            'Injuries Reported',
            'Property Damage',
            'Additional Notes',
            'Status',
            'Admin Notes',
            'Actioned By',
            'Actioned At',
            'Alert ID',
        ]);

        // Data rows
        foreach ($reports as $r) {
            $csv->insertOne([
                $r->id,
                $r->created_at->format('d M Y H:i'),
                $r->household?->name ?? '—',
                $r->household?->email ?? '—',
                $r->household?->phone ?? '—',
                $r->reporter?->name ?? '—',
                $r->reporter?->email ?? '—',
                ucfirst($r->outcome),
                $r->misuse_category ? ucwords(str_replace('_', ' ', $r->misuse_category)) : '—',
                $r->narrative,
                $r->arrived_at?->format('d M Y H:i') ?? '—',
                $r->departed_at?->format('d M Y H:i') ?? '—',
                $r->injuries_reported ? 'Yes' : 'No',
                $r->property_damage   ? 'Yes' : 'No',
                $r->additional_notes ?? '—',
                ucfirst($r->status),
                $r->admin_notes ?? '—',
                $r->actionedBy?->name ?? '—',
                $r->actioned_at?->format('d M Y H:i') ?? '—',
                $r->emergency_alert_id ?? '—',
            ]);
        }

        $filename = 'incident-reports-' . $request->date_from . '-to-' . $request->date_to . '.csv';

        return response((string) $csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
            'Cache-Control'       => 'no-store',
        ]);
    }

    // ── POST /api/admin/incident-reports/export/email ─────────────────────────
    public function emailExport(Request $request)
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to'   => 'required|date|after_or_equal:date_from',
            'emails'    => 'required|array|min:1|max:10',
            'emails.*'  => 'required|email',
            'formats'   => 'required|array|min:1',
            'formats.*' => 'in:pdf,csv',
            'status'    => 'nullable|in:pending,reviewed,warned,blocked,dismissed',
            'outcome'   => 'nullable|in:legitimate,misuse',
            'search'    => 'nullable|string|max:100',
        ]);

        $reports = $this->buildQuery($request)->get();

        if ($reports->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No reports found for the selected date range and filters.',
            ], 422);
        }

        $data = [
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

        // Build attachments
        $attachments = [];

        if (in_array('pdf', $request->formats)) {
            $pdf      = Pdf::loadView('exports.incident-reports-pdf', $data)
                ->setPaper('a4', 'landscape')
                ->setOptions(['defaultFont' => 'sans-serif', 'isHtml5ParserEnabled' => true]);
            $pdfName  = 'incident-reports-' . $request->date_from . '-to-' . $request->date_to . '.pdf';
            $attachments[] = [
                'content'  => $pdf->output(),
                'name'     => $pdfName,
                'mime'     => 'application/pdf',
            ];
        }

        if (in_array('csv', $request->formats)) {
            $csv = Writer::createFromFileObject(new SplTempFileObject());
            $csv->insertOne([
                'ID', 'Date', 'Household Name', 'Household Email', 'Household Phone',
                'Reporter', 'Reporter Email', 'Outcome', 'Misuse Category', 'Narrative',
                'Arrived At', 'Departed At', 'Injuries', 'Property Damage',
                'Additional Notes', 'Status', 'Admin Notes', 'Actioned By', 'Actioned At', 'Alert ID',
            ]);
            foreach ($reports as $r) {
                $csv->insertOne([
                    $r->id,
                    $r->created_at->format('d M Y H:i'),
                    $r->household?->name ?? '—',
                    $r->household?->email ?? '—',
                    $r->household?->phone ?? '—',
                    $r->reporter?->name ?? '—',
                    $r->reporter?->email ?? '—',
                    ucfirst($r->outcome),
                    $r->misuse_category ? ucwords(str_replace('_', ' ', $r->misuse_category)) : '—',
                    $r->narrative,
                    $r->arrived_at?->format('d M Y H:i') ?? '—',
                    $r->departed_at?->format('d M Y H:i') ?? '—',
                    $r->injuries_reported ? 'Yes' : 'No',
                    $r->property_damage   ? 'Yes' : 'No',
                    $r->additional_notes ?? '—',
                    ucfirst($r->status),
                    $r->admin_notes ?? '—',
                    $r->actionedBy?->name ?? '—',
                    $r->actioned_at?->format('d M Y H:i') ?? '—',
                    $r->emergency_alert_id ?? '—',
                ]);
            }
            $csvName = 'incident-reports-' . $request->date_from . '-to-' . $request->date_to . '.csv';
            $attachments[] = [
                'content'  => (string) $csv,
                'name'     => $csvName,
                'mime'     => 'text/csv',
            ];
        }

        // Send to each email
        foreach ($request->emails as $email) {
            Mail::to($email)->queue(new IncidentReportExportMail(
                dateFrom:    $request->date_from,
                dateTo:      $request->date_to,
                total:       $reports->count(),
                formats:     $request->formats,
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