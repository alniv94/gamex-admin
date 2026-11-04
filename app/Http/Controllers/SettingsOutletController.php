<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveOutletRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SettingsOutletController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('Outlets');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['Active', 'Inactive'])],
            'sort' => ['sometimes', Rule::in(['outlet_name', 'address', 'is_active', 'created_at'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', Rule::in([5, 10, 25, 50, 100])],
        ]);
        $outlets = DB::table('admin_outlets as o')
            ->leftJoin('barangays as b', 'b.id', '=', 'o.barangay_id')
            ->leftJoin('cities as c', 'c.id', '=', 'b.city_id')
            ->select(['o.id', 'o.outlet_name', 'o.barangay_id', 'o.address', 'o.is_active', 'o.created_at',
                'o.management_version as version', 'b.name as barangay', 'c.id as city_id', 'c.name as city'])
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(function ($query) use ($search): void {
                foreach (['o.outlet_name', 'o.address', 'b.name', 'c.name'] as $column) {
                    $query->orWhere($column, 'like', '%'.$search.'%');
                }
            }))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('o.is_active', $status))
            ->orderBy('o.'.($filters['sort'] ?? 'created_at'), $filters['direction'] ?? 'desc')
            ->orderByDesc('o.id')->paginate($filters['per_page'] ?? 25)->withQueryString();

        return Inertia::render('settings/Outlets', [
            'outlets' => $outlets, 'filters' => $filters,
            'cities' => Inertia::once(fn () => DB::table('cities')->orderBy('name')->get(['id', 'name'])),
        ]);
    }

    public function barangays(Request $request): JsonResponse
    {
        Gate::authorize('Outlets');
        $data = $request->validate(['city_id' => ['required', 'integer', Rule::exists('cities', 'id')]]);

        return response()->json(['records' => DB::table('barangays')->where('city_id', $data['city_id'])
            ->orderBy('name')->get(['id', 'name'])]);
    }

    public function store(SaveOutletRequest $request): RedirectResponse
    {
        $data = $this->outletData($request);
        DB::transaction(function () use ($request, $data): void {
            $id = DB::table('admin_outlets')->insertGetId([
                ...$data, 'management_version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($request, $id, 'created', ['after' => $data]);
        });
        Inertia::flash('success', 'Outlet created.');

        return back(303);
    }

    public function update(SaveOutletRequest $request, int $outlet): RedirectResponse
    {
        DB::transaction(function () use ($request, $outlet): void {
            $existing = DB::table('admin_outlets')->where('id', $outlet)->lockForUpdate()->first();
            abort_unless($existing !== null, 404);
            if ((int) $existing->management_version !== (int) $request->validated('version')) {
                throw ValidationException::withMessages(['version' => 'This outlet was changed by another administrator. Refresh and try again.']);
            }
            $data = $this->outletData($request);
            DB::table('admin_outlets')->where('id', $outlet)->update([
                ...$data, 'management_version' => $existing->management_version + 1, 'updated_at' => now(),
            ]);
            $before = array_intersect_key((array) $existing, $data);
            $this->audit($request, $outlet, 'updated', ['before' => $before, 'after' => $data]);
        });
        Inertia::flash('success', 'Outlet updated.');

        return back(303);
    }

    /** @return array<string, mixed> */
    private function outletData(SaveOutletRequest $request): array
    {
        return $request->safe()->only(['outlet_name', 'barangay_id', 'address', 'is_active']);
    }

    /** @param array<string, mixed> $details */
    private function audit(Request $request, int $outlet, string $action, array $details): void
    {
        DB::table('admin_outlet_audits')->insert([
            'user_id' => $request->user()->id, 'outlet_id' => $outlet, 'action' => $action,
            'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
