<?php

namespace App\Http\Controllers;

use App\Http\Requests\BuildingRequest;
use App\Http\Resources\BuildingResource;
use App\Http\Resources\UnitResource;
use App\Models\Building;
use App\Models\Unit;
use Illuminate\Http\Request;

class BuildingController extends Controller
{
    private function query()
    {
        return Building::query()->withCount(['units',
            'units as available_count' => fn ($q) => $q->whereRaw(Unit::availabilitySql().' = ?', ['available']),
            'units as sold_count' => fn ($q) => $q->whereRaw(Unit::availabilitySql().' = ?', ['sold']),
            'units as rented_count' => fn ($q) => $q->whereRaw(Unit::availabilitySql().' = ?', ['rented']),
        ]);
    }

    public function index(Request $request)
    {
        $filters = $request->validate(['search' => 'nullable|string|max:150', 'status' => 'nullable|in:all,active,inactive', 'page' => 'nullable|integer|min:1']);
        $query = $this->query();
        if ($search = $filters['search'] ?? null) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('address', 'like', '%'.$search.'%'));
        }
        if (($filters['status'] ?? 'all') !== 'all') {
            $query->where('is_active', $filters['status'] === 'active');
        }

        return BuildingResource::collection($query->orderByDesc('id')->paginate(12)->withQueryString());
    }

    public function store(BuildingRequest $request)
    {
        $building = Building::create($request->validated());
        logger()->info('Building created', ['building_id' => $building->id, 'user_id' => $request->user()->id]);

        return (new BuildingResource($this->query()->findOrFail($building->id)))->response()->setStatusCode(201);
    }

    public function show(Building $building)
    {
        return new BuildingResource($this->query()->findOrFail($building->id));
    }

    public function update(BuildingRequest $request, Building $building)
    {
        $building->update($request->validated());
        logger()->info('Building updated', ['building_id' => $building->id, 'user_id' => $request->user()->id, 'fields' => array_keys($request->validated())]);

        return new BuildingResource($this->query()->findOrFail($building->id));
    }

    public function units(Request $request, Building $building)
    {
        $request->validate(['page' => 'nullable|integer|min:1']);
        $page = $building->units()->withAvailability()->with('building')->orderBy('number')->paginate(20);

        return UnitResource::collection($page);
    }

    public function options()
    {
        return response()->json(['data' => Building::orderBy('name')->get(['id', 'name', 'is_active'])]);
    }

    public function destroy()
    {
        return response()->json(['message' => 'لا يمكن حذف المباني حفاظًا على السجلات. يمكنك إيقاف المبنى من تعديل البيانات.'], 409);
    }
}
