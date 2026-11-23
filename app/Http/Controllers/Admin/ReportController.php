<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\UsageReportAction;
use App\Contracts\ImageGenerationProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UsageReportRequest;
use App\Support\UsageReportPresenter;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    /**
     * Admin usage & profit report — local succeeded-generation costs reconciled
     * against Pixelforge's own GET /v1/usage billing for the same UTC range.
     */
    public function __invoke(
        UsageReportRequest $request,
        ImageGenerationProvider $provider,
        UsageReportAction $report,
        UsageReportPresenter $presenter,
    ): Response {
        [$start, $end] = $request->range();

        $usage = $provider->getUsage($start->toDateString(), $end->toDateString());

        return Inertia::render('Admin/Report', $presenter->page(
            $report->execute($start, $end, $usage),
            $start,
            $end,
            UsageReportRequest::MAX_RANGE_DAYS,
        ));
    }
}
