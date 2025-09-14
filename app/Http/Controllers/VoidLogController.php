<?php

namespace App\Http\Controllers;

use App\Helpers;
use App\Http\Resources\VoidLogResource;
use App\Models\VoidLog;
use Illuminate\Http\Request;

class VoidLogController extends Controller
{
    public function index(Request $request)
    {
        $range = Helpers::getDateRange($request->start_date, $request->end_date);

        $data = VoidLog::query()->when($request->input('search'), function ($query, $search) {
            return $query->search($search);
        })->when($request->input('start_date'), function ($query) use ($range) {
            $query->whereBetween('created_at', $range);
        })->with([
            'approver:id,name', 
            'user:id,name',    
            'saleItem.product:id,name' 
        ])

            ->paginate($request?->data['per_page'] ?? 10);

        return inertia('VoidLog/Index', [
            'items' => VoidLogResource::collection($data),
            'filters' => [
                'start_date' => $request->start_date,
                'end_date' => $request->end_date
            ]
        ]);
    }
}
