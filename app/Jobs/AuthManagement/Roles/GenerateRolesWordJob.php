<?php

namespace App\Jobs\AuthManagement\Roles;

use App\Exports\AuthManagement\Roles\RolesWord;
use App\Models\Download;
use Illuminate\Support\Facades\Storage;

class GenerateRolesWordJob extends BaseRoleExportJob
{
    protected string $type      = 'word';
    protected string $extension = 'docx';

    protected function executeExport(Download $download): void
    {
        $query    = $this->buildQuery()->withCount(['permissions', 'users']);
        $count    = (clone $query)->count();
        $roles    = $query->cursor();
        $tempFile = tempnam(sys_get_temp_dir(), 'roles_export') . '.docx';

        $opts = $this->options + ['timezone' => $this->userTimezone];

        (new RolesWord())->generate(
            roles:          $roles,
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
