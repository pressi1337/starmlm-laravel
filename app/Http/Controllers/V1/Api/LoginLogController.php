<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\LoginLog;
use App\Models\User;
use App\Services\IpLocationService;
use App\Traits\HandlesJson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Login history — read-only audit list for the admin.
 *
 * Super-admin only (gated by `role:0` on the route): it shows IPs and devices
 * for every account, including other admins.
 */
class LoginLogController extends Controller
{
    use HandlesJson;

    protected array $sortable = ['id', 'username', 'logged_in_at'];

    public function index(Request $request)
    {
        try {
            $sort_column = $request->query('sort_column', $request->query('sortBy', 'id'));
            if (!in_array($sort_column, $this->sortable, true)) {
                $sort_column = 'id';
            }
            $sort_direction = strtoupper((string) $request->query('sort_direction', $request->query('sortDir', 'DESC'))) === 'ASC' ? 'ASC' : 'DESC';
            $page_size = (int) $request->query('page_size', 10);
            $page_number = max(1, (int) $request->query('page_number', 1));
            $search_term = trim((string) $request->query('search', ''));
            $search_param = $this->safeJsonDecode($request->query('search_param', '[]'));

            $query = LoginLog::query();

            if ($search_term !== '') {
                $like = '%' . $search_term . '%';
                $query->where(function ($q) use ($like) {
                    $q->where('username', 'LIKE', $like)
                        ->orWhere('customer_id', 'LIKE', $like)
                        ->orWhere('ip_address', 'LIKE', $like)
                        // Paste a device id here to see every account that has
                        // logged in from that phone.
                        ->orWhere('device_id', 'LIKE', $like)
                        ->orWhere('device_model', 'LIKE', $like)
                        ->orWhere('device_brand', 'LIKE', $like)
                        ->orWhere('city', 'LIKE', $like)
                        ->orWhere('country', 'LIKE', $like)
                        ->orWhere('isp', 'LIKE', $like);
                });
            }

            // Filter by who logged in (User / Sub Admin / Admin).
            if (isset($search_param['role']) && $search_param['role'] !== '') {
                $query->where('role', (int) $search_param['role']);
            }

            // Date-only comparison so a same-day filter covers the whole day.
            $fromDate = $search_param['fromdate'] ?? null;
            $toDate = $search_param['todate'] ?? null;
            if ($fromDate && $toDate) {
                $query->whereDate('logged_in_at', '>=', $fromDate)
                    ->whereDate('logged_in_at', '<=', $toDate);
            } elseif ($fromDate) {
                $query->whereDate('logged_in_at', '>=', $fromDate);
            } elseif ($toDate) {
                $query->whereDate('logged_in_at', '<=', $toDate);
            }

            $total_records = (clone $query)->count();

            $rows = $query->orderBy($sort_column, $sort_direction)
                ->when($page_size > 0, function ($q) use ($page_size, $page_number) {
                    return $q->skip(($page_number - 1) * $page_size)->take($page_size);
                })
                ->get();

            // Resolve city/ISP for any IP on this page we haven't seen before.
            $this->fillLocations($rows);

            // How many DISTINCT accounts each device on this page has been used
            // for. >1 is the signal worth investigating: one phone, several
            // accounts. Done as a single query for the whole page.
            $deviceIds = $rows->pluck('device_id')->filter()->unique()->values()->all();
            $accountsPerDevice = [];
            if (!empty($deviceIds)) {
                $accountsPerDevice = \Illuminate\Support\Facades\DB::table('login_logs')
                    ->whereIn('device_id', $deviceIds)
                    ->selectRaw('device_id, COUNT(DISTINCT user_id) as accounts')
                    ->groupBy('device_id')
                    ->pluck('accounts', 'device_id')
                    ->all();
            }

            $items = $rows
                ->map(function ($row) use ($accountsPerDevice) {
                    return [
                        'id'           => $row->id,
                        'user_id'      => $row->user_id,
                        'username'     => $row->username ?? 'N/A',
                        'customer_id'  => $row->customer_id,
                        'role'         => (int) $row->role,
                        'role_label'   => $row->roleLabel(),
                        'device'       => $row->device,
                        'os'           => $row->os,
                        'browser'      => $row->browser,
                        'device_label' => $row->deviceLabel(),
                        'device_id'    => $row->device_id,
                        'device_model' => $row->device_model,
                        'device_brand' => $row->brand(),
                        // Ready to print, e.g. "Samsung SM-G991B".
                        'device_name'  => $row->deviceName(),
                        'screen'       => $row->screen,
                        // 1 = only this account uses the device. >1 means the
                        // same phone has signed into that many accounts.
                        'accounts_on_device' => $row->device_id
                            ? (int) ($accountsPerDevice[$row->device_id] ?? 1)
                            : null,
                        'ip_address'   => $row->ip_address,
                        'city'         => $row->city,
                        'region'       => $row->region,
                        'country'      => $row->country,
                        // The network the IP belongs to. A mobile carrier here
                        // explains a shared IP on its own.
                        'isp'          => $row->isp,
                        'location'     => $row->locationLabel(),
                        'logged_in_at' => $row->logged_in_at
                            ? $row->logged_in_at->format('d-m-Y h:i A')
                            : '-',
                    ];
                });

            return response()->json([
                'success'  => true,
                'message'  => 'Success',
                'data'     => $items,
                'meta'     => [
                    'roles' => [
                        ['value' => User::ROLE_USER,        'label' => 'User'],
                        ['value' => User::ROLE_SUB_ADMIN,   'label' => 'Sub Admin'],
                        ['value' => User::ROLE_SUPER_ADMIN, 'label' => 'Admin'],
                    ],
                ],
                'pageInfo' => [
                    'page_size'     => $page_size,
                    'page_number'   => $page_number,
                    'total_pages'   => $page_size > 0 ? (int) ceil($total_records / max(1, $page_size)) : 1,
                    'total_records' => $total_records,
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('LoginLog index failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Something went wrong'], 500);
        }
    }

    /**
     * Fill in city / ISP for the rows being displayed, on first view only.
     *
     * Deliberately not done at login time: a login must never wait on an
     * outside service. Instead the first admin who looks at a row resolves it,
     * the answer is written to the database, and every future view reads it
     * straight from there. The whole page costs one HTTP call, and rows sharing
     * an IP are all updated together — so the table backfills itself as it's
     * browsed and the work keeps shrinking.
     *
     * Failure is not fatal: unresolved rows simply keep showing the raw IP and
     * get another chance next time.
     */
    private function fillLocations($rows): void
    {
        try {
            $pending = $rows->filter(function ($row) {
                return $row->location_checked_at === null && !empty($row->ip_address);
            });

            if ($pending->isEmpty()) {
                return;
            }

            $ips = $pending->pluck('ip_address')->unique()->values()->all();
            $located = app(IpLocationService::class)->lookupMany($ips);

            foreach ($located as $ip => $location) {
                $payload = array_merge($location, ['location_checked_at' => now()]);

                // Every unresolved row on this IP, not just the ones on screen.
                LoginLog::where('ip_address', $ip)
                    ->whereNull('location_checked_at')
                    ->update($payload);

                // Reflect it on the already-loaded rows so this response shows it.
                foreach ($pending as $row) {
                    if ($row->ip_address === $ip) {
                        $row->forceFill($payload);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('LoginLog location backfill failed', ['error' => $e->getMessage()]);
        }
    }
}
