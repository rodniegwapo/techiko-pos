<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
use App\Models\Sale;
use App\Models\SaleItem;
use DB;

class DashboardController extends Controller
{
    /**
     * Get sales chart data based on time range
     */
    public function getSalesChartData(Request $request): JsonResponse
    {
        $request->validate([
            'time_range' => 'required|in:daily,weekly,monthly',
            'location_id' => 'nullable' // Remove validation since sales table doesn't have location_id
        ]);

        $timeRange = $request->input('time_range');
        $locationId = $request->input('location_id'); // Keep for future use

        $data = match ($timeRange) {
            'daily' => $this->getWeeklyData(),
            'weekly' => $this->getMonthlyData(),
            'monthly' => $this->getYearlyData(),
            default => $this->getWeeklyData()
        };

        return response()->json($data);
    }

    /**
     * Get weekly data (Monday to Sunday of current week)
     */
    private function getWeeklyData(): array
    {
        $startOfWeek = Carbon::now()->startOfWeek();
        $endOfWeek = Carbon::now()->endOfWeek();

        $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $categories = [];
        $salesData = [];
        $transactionsData = [];

        for ($i = 0; $i < 7; $i++) {
            $currentDay = $startOfWeek->copy()->addDays($i);
            $categories[] = $days[$i];

            $dayData = $this->getDaySalesData($currentDay);
            $salesData[] = $dayData['sales'];
            $transactionsData[] = $dayData['transactions'];
        }

        return [
            'categories' => $categories,
            'salesData' => $salesData,
            'transactionsData' => $transactionsData
        ];
    }

    /**
     * Get monthly data (weeks within current month)
     */
    private function getMonthlyData(): array
    {
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        $weeks = [];
        $salesData = [];
        $transactionsData = [];

        $currentWeek = 1;
        $currentDate = $startOfMonth->copy();

        while ($currentDate->lte($endOfMonth)) {
            $weekStart = $currentDate->copy();
            $weekEnd = $currentDate->copy()->addDays(6);

            if ($weekEnd->gt($endOfMonth)) {
                $weekEnd = $endOfMonth->copy();
            }

            $weeks[] = "Week {$currentWeek}";

            $weekData = $this->getWeekSalesData($weekStart, $weekEnd);
            $salesData[] = $weekData['sales'];
            $transactionsData[] = $weekData['transactions'];

            $currentDate->addWeek();
            $currentWeek++;
        }

        return [
            'categories' => $weeks,
            'salesData' => $salesData,
            'transactionsData' => $transactionsData
        ];
    }

    /**
     * Get yearly data (months of current year)
     */
    private function getYearlyData(): array
    {
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 
                  'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $salesData = [];
        $transactionsData = [];

        for ($month = 1; $month <= 12; $month++) {
            $monthStart = Carbon::now()->year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '-01';
            $monthEnd = Carbon::parse($monthStart)->endOfMonth();

            $monthData = $this->getMonthSalesData($monthStart, $monthEnd);
            $salesData[] = $monthData['sales'];
            $transactionsData[] = $monthData['transactions'];
        }

        return [
            'categories' => $months,
            'salesData' => $salesData,
            'transactionsData' => $transactionsData
        ];
    }

    /**
     * Get sales data for a specific day
     */
    private function getDaySalesData(Carbon $date): array
    {
        $query = Sale::whereDate('created_at', $date->format('Y-m-d'))
            ->where('payment_status', 'paid');

        $sales = $query->sum('grand_total');
        $transactions = $query->count();

        return [
            'sales' => (float) $sales,
            'transactions' => $transactions
        ];
    }

    /**
     * Get sales data for a week
     */
    private function getWeekSalesData(Carbon $startDate, Carbon $endDate): array
    {
        $query = Sale::whereBetween('created_at', [$startDate, $endDate])
            ->where('payment_status', 'paid');

        $sales = $query->sum('grand_total');
        $transactions = $query->count();

        return [
            'sales' => (float) $sales,
            'transactions' => $transactions
        ];
    }

    /**
     * Get sales data for a month
     */
    private function getMonthSalesData(string $startDate, Carbon $endDate): array
    {
        $query = Sale::whereBetween('created_at', [$startDate, $endDate])
            ->where('payment_status', 'paid');

        $sales = $query->sum('grand_total');
        $transactions = $query->count();

        return [
            'sales' => (float) $sales,
            'transactions' => $transactions
        ];
    }
}
