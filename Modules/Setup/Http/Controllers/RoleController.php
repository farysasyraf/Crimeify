<?php

namespace Modules\Setup\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function index(): View
    {
        return view('setup::roles.index', ['roles' => Role::withCount('users')->orderBy('Name')->get()]);
    }

    public function create(): View
    {
        return view('setup::roles.create', ['role' => new Role]);
    }

    public function store(Request $request): RedirectResponse
    {
        $input = $this->validated($request);

        try {
            $role = Role::create($input);
        } catch (UniqueConstraintViolationException) {
            return $this->duplicateName($input['Name']);
        }

        return redirect(page_url('roles'))->with('message', "Added the {$role->Name} role.");
    }

    public function edit(Role $role): View
    {
        return view('setup::roles.edit', compact('role'));
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $input = $this->validated($request, $role);

        if ($role->isAdmin() && $input['Name'] !== Role::Admin) {
            throw ValidationException::withMessages(['name' => "The ADMIN role keeps its name: it's the role that opens the Routes page."]);
        }

        try {
            $role->update($input);
        } catch (UniqueConstraintViolationException) {
            return $this->duplicateName($input['Name']);
        }

        return redirect(page_url('roles'))->with('message', "Saved changes to {$role->Name}.");
    }

    public function delete(Role $role): View
    {
        return view('setup::roles.delete', ['role' => $role->loadCount(['users', 'menuItems'])]);
    }

    public function destroy(Role $role): RedirectResponse
    {
        abort_if($role->isAdmin(), 403, "The ADMIN role can't be deleted: it's the role that opens the Routes page.");

        // dbo.UserRoles cascades, so users who had this role simply lose it.
        $role->delete();

        return redirect(page_url('roles'))->with('message', "Deleted the {$role->Name} role.");
    }

    /**
     * Validate the form and map it onto the Roles columns.
     *
     * @return array{Name: string, Description: ?string}
     */
    private function validated(Request $request, ?Role $role = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique(Role::class, 'Name')->ignore($role)],
            'description' => ['nullable', 'string', 'max:255'],
        ], [
            'name.unique' => "A role named ':input' already exists.",
        ]);

        return ['Name' => $data['name'], 'Description' => $data['description'] ?? null];
    }

    // The unique rule catches duplicates up front; this covers two saves racing each other.
    private function duplicateName(string $name): RedirectResponse
    {
        return back()->withInput()->withErrors([
            'name' => "A role named '{$name}' already exists.",
        ]);
    }
}
