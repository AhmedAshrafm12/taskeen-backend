<?php

namespace App\Http\Controllers;

use App\Http\Requests\UnitRequest;
use App\Http\Resources\UnitResource;
use App\Models\Building;
use App\Models\Unit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UnitController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => 'nullable|string|max:150', 'building_id' => 'nullable|integer|exists:buildings,id',
            'type' => 'nullable|in:apartment,shop,office,other',
            'status' => 'nullable|in:available,sold,rented', 'page' => 'nullable|integer|min:1',
        ]);
        $query = Unit::withAvailability()->with('building');
        foreach (['building_id', 'type'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['status'])) {
            $query->whereRaw(Unit::availabilitySql().' = ?', [$filters['status']]);
        }
        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = $filters['search'];
            $query->where(fn ($q) => $q->where('number', 'like', '%'.Unit::normalizeNumber($search).'%')
                ->orWhereHas('building', fn ($b) => $b->where('name', 'like', '%'.$search.'%')));
        }

        return UnitResource::collection($query->orderByDesc('id')->paginate(20)->withQueryString());
    }

    public function store(UnitRequest $request)
    {
        $data = $request->validated();
        unset($data['status']);
        $unit = $this->save(function () use ($data) {
            $building = Building::lockForUpdate()->findOrFail($data['building_id']);
            if (! $building->is_active) {
                throw ValidationException::withMessages(['building_id' => 'لا يمكن إضافة وحدات إلى مبنى متوقف. أعد تفعيل المبنى أولًا.']);
            }

            return Unit::create($data)->refresh();
        });
        logger()->info('Unit created', ['unit_id' => $unit->id, 'user_id' => $request->user()->id]);

        return (new UnitResource($unit->load('building')))->response()->setStatusCode(201);
    }

    public function show(Unit $unit)
    {
        return new UnitResource($unit->load('building'));
    }

    public function update(UnitRequest $request, Unit $unit)
    {
        $data = $request->validated();
        unset($data['status']);
        $unit = $this->save(function () use ($data, $unit) {
            $unit = Unit::lockForUpdate()->findOrFail($unit->id);
            $building = Building::lockForUpdate()->findOrFail($data['building_id']);
            if ($unit->building_id != $building->id && ! $building->is_active) {
                throw ValidationException::withMessages(['building_id' => 'لا يمكن نقل الوحدة إلى مبنى متوقف.']);
            }
            if ($unit->identityLocked()) {
                foreach (['building_id', 'number', 'floor', 'type', 'area'] as $field) {
                    if (array_key_exists($field, $data) && (string) $unit->$field !== (string) $data[$field]
                        && ! ($field === 'area' && $unit->area !== null && $data[$field] !== null && (float) $unit->area === (float) $data[$field])) {
                        throw ValidationException::withMessages([$field => 'الوحدة مرتبطة بسجل تعاقدات محفوظ. يمكنك تعديل أسعار العرض والملاحظات فقط.']);
                    }
                }
            }
            $unit->update($data);

            return $unit;
        });
        logger()->info('Unit updated', ['unit_id' => $unit->id, 'user_id' => $request->user()->id, 'fields' => array_keys($data)]);

        return new UnitResource($unit->load('building'));
    }

    private function save(callable $callback): Unit
    {
        try {
            return DB::transaction($callback);
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['number' => 'رقم الوحدة مستخدم بالفعل داخل هذا المبنى.']);
        }
    }
}
