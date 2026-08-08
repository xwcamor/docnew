<?php

namespace App\Jobs\BusinessManagement\ApproverRoles;

use App\Exports\BusinessManagement\ApproverRoles\ApproverRolesWord;
use App\Models\Download;
use Illuminate\Support\Facades\Storage;

class GenerateApproverRolesWordJob extends BaseApproverRoleExportJob
{
    protected string $type      = 'word';
    protected string $extension = 'docx';

    protected function executeExport(Download $download): void
    {
        $query     = $this->buildQuery();
        $count     = (clone $query)->count();
        $approver_roles = $query->cursor();
        $tempFile  = tempnam(sys_get_temp_dir(), 'approver_roles_export') . '.docx';

        $opts = $this->options + ['timezone' => $this->userTimezone];

        (new ApproverRolesWord())->generate(
            approver_roles:      $approver_roles,
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
