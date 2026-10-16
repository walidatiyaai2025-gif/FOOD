<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EnterpriseWorkspaceComposer;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class EnterpriseWorkspaceController extends Controller
{
    public function __construct(
        private readonly EnterpriseWorkspaceComposer $workspaces,
        private readonly AdminNavigation $navigation,
    ) {}

    public function __invoke(Request $request, ?string $workspace = null): View
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $available = $this->workspaces->availableWorkspaceKeys($actor);
        abort_if($available === [], 403);

        $workspace ??= $available[0];

        try {
            $composed = $this->workspaces->compose($actor, $workspace);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        abort_if($composed === null, 403);

        return view('admin.enterprise-workspaces', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'enterprise_workspaces',
            'workspace' => $composed,
        ]);
    }
}
