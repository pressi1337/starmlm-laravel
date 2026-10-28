<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\PolicyPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Public policy documents (Privacy, Refund & Cancellation, Shipping).
 *
 * `index()` and `show()` are public on purpose — payment gateways require the
 * policies to be readable without logging in, and the website renders them at
 * /privacy-policy, /refund-policy and /shipping-policy.
 *
 * `upsert()` is admin-only and follows the same single-document-per-slug
 * pattern as TermsAndConditionController.
 */
class PolicyPageController extends Controller
{
    /** GET policies — every published policy (slug + title), for menus. */
    public function index()
    {
        $pages = PolicyPage::where('is_deleted', 0)
            ->where('is_active', 1)
            ->get(['slug', 'title', 'updated_at']);

        return response()->json(['success' => true, 'data' => $pages], 200);
    }

    /** GET policies/{slug} — one policy's content. */
    public function show(string $slug)
    {
        $page = PolicyPage::findBySlug($slug);

        if (!$page || !$page->is_active) {
            return response()->json(['success' => false, 'message' => 'Policy not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $page], 200);
    }

    /** GET policies-admin — all policies including unpublished drafts. */
    public function adminIndex()
    {
        $pages = PolicyPage::where('is_deleted', 0)->orderBy('id')->get();

        return response()->json(['success' => true, 'data' => $pages], 200);
    }

    /** POST policies/upsert — create or update one policy by slug. */
    public function upsert(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'slug'      => 'required|string|max:60',
            'title'     => 'required|string|max:150',
            'content'   => 'required|string',
            'is_active' => 'nullable|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $authId = Auth::id();
            $page = PolicyPage::findBySlug($request->slug);

            if (!$page) {
                $page = new PolicyPage();
                $page->slug = $request->slug;
                $page->created_by = $authId;
            }

            $page->title = $request->title;
            $page->content = $request->content;
            $page->is_active = $request->filled('is_active') ? (int) $request->is_active : 1;
            $page->updated_by = $authId;
            $page->save();

            return response()->json([
                'status'  => true,
                'message' => $page->title . ' saved successfully',
                'data'    => $page,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('PolicyPage upsert failed', ['error' => $e->getMessage()]);
            return response()->json(['status' => false, 'message' => 'Something went wrong'], 500);
        }
    }
}
