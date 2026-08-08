<?php

namespace App\Jobs\BusinessManagement\ApprovalRules;

use App\Exports\BusinessManagement\ApprovalRules\ApprovalRulesWord;
use App\Models\Download;
use Illuminate\Support\Facades\Storage;

class GenerateApprovalRulesWordJob extends BaseApprovalRuleExportJob
{
    protected string $type      = 'word';
    protected string $extension = 'docx';

    protected function executeExport(Download $download): void
    {
        $query     = $this->buildQuery();
        $count     = (clone $query)->count();
        $approval_rules = $query->cursor();
        $tempFile  = tempnam(sys_get_temp_dir(), 'approval_rules_export') . '.docx';

        $opts = $this->options + ['timezone' => $this->userTimezone];

        (new ApprovalRulesWord())->generate(
            approval_rules:      $approval_rules,
            filename:       $tempFile,
            options:        $opts,
            filtersSummary: $this->buildFiltersSummary(),
            generatedBy:    optional(\App\Models\User::find($this->userId))->name ?? '—',
            count:          $count,
        );

        $content = file_get_contents($tempFile);
        unlink($tempFile);

        $path = 'downloads/' . $download->filename;
        Storage::disk($download->disk)->put($path, $content);

        $download->update(['path' => $path, 'status' => 'ready']);
    }
}
