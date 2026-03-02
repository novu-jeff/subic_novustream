<?php

namespace App\Http\Controllers;

use App\Services\AdminService;
use App\Services\RoleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;
use App\Models\Zone;

class AdminController extends Controller
{

    public $adminService;
    public $roleService;

    public function __construct(AdminService $adminService, RoleService $roleService) {

        $this->middleware(function ($request, $next) {
            if (!Gate::allows('superadmin')) {
                abort(403, 'Only Superadmin can manage personnels.');
            }
            return $next($request);
        });

        $this->adminService = $adminService;
        $this->roleService = $roleService;
    }

    public function index() {

        $data = $this->adminService::getData();

        if(request()->ajax()) {
            return $this->datatable($data);
        }

        return view('admins.index', compact('data'));
    }

    // public function create() {

    //     $roles = $this->roleService::getData();

    //     return view('admins.form', compact('roles'));
    // }

    public function create() {
        $roles = $this->roleService::getData();
        $zones = Zone::all();
        return view('admins.form', compact('roles', 'zones'));
    }

    public function edit(int $id) {
        $data = $this->adminService::getData($id);
        $roles = $this->roleService::getData();
        $zones = Zone::all();
        return view('admins.form', compact('data', 'roles', 'zones'));
    }

    public function store(Request $request) {
        $payload = $request->all();
        $response = $this->adminService::create($payload);

        if ($response['status'] === 'success') {
            $roleLabel = ucwords($payload['role']); // Capitalize role
            $name = $payload['name'];
            return redirect()->back()->with('alert', [
                'status' => 'success',
                'message' => "{$roleLabel} {$name} added successfully."
            ]);
        }

        return redirect()->back()->withInput()->with('alert', [
            'status' => 'error',
            'message' => $response['message']
        ]);
    }

    public function update(int $id, Request $request) {
        $payload = $request->all();
        $response = $this->adminService::update($id, $payload);

        if ($response['status'] === 'success') {
            $roleLabel = ucwords($payload['role']); // Capitalize role
            $name = $payload['name'];
            return redirect()->back()->with('alert', [
                'status' => 'success',
                'message' => "{$roleLabel} {$name} updated successfully."
            ]);
        }

        return redirect()->back()->withInput()->with('alert', [
            'status' => 'error',
            'message' => $response['message']
        ]);
    }


    public function destroy(int $id) {

        $user = $this->adminService::getData($id);
        $response = $this->adminService::delete($id);

        if ($response['status'] === 'success') {
            $roleLabel = 'User';

            if ($user && isset($user->user_type)) {
                $roleLabel = ucwords(str_replace('_', ' ', $user->user_type));
            }

            return response()->json([
                'status' => 'success',
                'message' => "{$roleLabel} deleted successfully.",
            ]);

        } else {
            return response()->json([
                'status' => 'error',
                'message' => $response['message']
            ]);
        }
    }

    public function datatable($query)
    {
        return DataTables::of($query)
            ->addIndexColumn()
            ->addColumn('actions', function ($row) {
                return '
                <div class="d-flex align-items-center gap-2">
                    <a href="' . route('admins.edit', $row->id) . '"
                        class="btn btn-secondary text-white text-uppercase fw-bold"
                        id="update-btn" data-id="' . e($row->id) . '">
                        <i class="bx bx-edit-alt"></i>
                    </a>
                    <button class="btn btn-danger text-white text-uppercase fw-bold btn-delete" id="delete-btn" data-id="' . e($row->id) . '">
                        <i class="bx bx-trash"></i>
                    </button>
                </div>';
            })
            ->rawColumns(['actions'])
            ->make(true);
    }
}
