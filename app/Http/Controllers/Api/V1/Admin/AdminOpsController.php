<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\CircleJoinRequest;
use App\Models\CoinClaimRequest;
use App\Models\Event;
use App\Models\Impact;
use App\Models\ImpactAction;
use App\Models\LeaderInterestSubmission;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\PeerRecommendation;
use App\Models\Post;
use App\Models\PostReport;
use App\Models\User;
use App\Models\VisitorRegistration;
use App\Services\Admin\AdminAuditService;
use App\Services\Circles\CircleJoinRequestService;
use App\Services\Impacts\ImpactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminOpsController extends BaseApiController
{
    public function __construct(
        private readonly CircleJoinRequestService $joinService,
        private readonly ImpactService $impactService,
        private readonly AdminAuditService $audit,
    ) {
    }

    // Join requests
    public function joinRequests(Request $request): JsonResponse { return $this->success(CircleJoinRequest::query()->latest('created_at')->paginate(20)); }
    public function joinRequestShow(string $id): JsonResponse { return $this->success(CircleJoinRequest::query()->findOrFail($id)); }
    public function joinCdApprove(Request $request, string $id): JsonResponse { return $this->success($this->joinService->approveByCd(CircleJoinRequest::findOrFail($id), $request->user())); }
    public function joinCdReject(Request $request, string $id): JsonResponse { $v=$request->validate(['rejection_reason'=>'required|string|max:500']); return $this->success($this->joinService->rejectByCd(CircleJoinRequest::findOrFail($id), $request->user(), $v['rejection_reason'])); }
    public function joinIdApprove(Request $request, string $id): JsonResponse { return $this->success($this->joinService->approveById(CircleJoinRequest::findOrFail($id), $request->user())); }
    public function joinIdReject(Request $request, string $id): JsonResponse { $v=$request->validate(['rejection_reason'=>'required|string|max:500']); return $this->success($this->joinService->rejectById(CircleJoinRequest::findOrFail($id), $request->user(), $v['rejection_reason'])); }
    public function joinMarkPaid(string $id): JsonResponse { $x=CircleJoinRequest::findOrFail($id); $x->status='paid'; $x->save(); return $this->success($x); }
    public function joinCancel(string $id): JsonResponse { $x=CircleJoinRequest::findOrFail($id); $x->status='cancelled'; $x->save(); return $this->success($x); }

    // Impacts
    public function impacts(Request $request): JsonResponse { return $this->success(Impact::with(['user','impactedPeer'])->latest('created_at')->paginate(20)); }
    public function impactShow(string $id): JsonResponse { return $this->success(Impact::with(['user','impactedPeer'])->findOrFail($id)); }
    public function impactApprove(Request $request, string $id): JsonResponse { return $this->success($this->impactService->approveImpact(Impact::findOrFail($id), $request->user(), $request->input('remarks'))); }
    public function impactReject(Request $request, string $id): JsonResponse { $v=$request->validate(['rejection_reason'=>'required|string|max:500']); return $this->success($this->impactService->rejectImpact(Impact::findOrFail($id), $request->user(), $v['rejection_reason'])); }
    public function impactsPending(): JsonResponse { return $this->success(Impact::query()->where('status','pending')->latest('created_at')->paginate(20)); }
    public function impactsHistory(Request $request): JsonResponse { $q=Impact::query(); $q->when($request->user_id, fn($x)=>$x->where('user_id',$request->user_id))->when($request->status, fn($x)=>$x->where('status',$request->status))->when($request->impact_type_id, fn($x)=>$x->where('impact_action_id',$request->impact_type_id)); return $this->success($q->latest('created_at')->paginate(20)); }

    public function impactActions(): JsonResponse { return $this->success(ImpactAction::query()->orderBy('title')->get()); }
    public function impactActionStore(Request $request): JsonResponse { $v=$request->validate(['title'=>'required|string|max:255','status'=>'nullable|string']); return $this->success(ImpactAction::query()->create($v)); }
    public function impactActionUpdate(Request $request, string $id): JsonResponse { $x=ImpactAction::findOrFail($id); $x->fill($request->only(['title','status']))->save(); return $this->success($x); }
    public function impactActionDelete(string $id): JsonResponse { ImpactAction::where('id',$id)->delete(); return $this->success(['deleted'=>true]); }

    // Coins
    public function coinClaims(): JsonResponse { return $this->success(CoinClaimRequest::with('user:id,display_name,email')->latest('created_at')->paginate(20)); }
    public function coinClaimShow(string $id): JsonResponse { return $this->success(CoinClaimRequest::with('user')->findOrFail($id)); }
    public function coinClaimApprove(Request $request, string $id): JsonResponse { $claim=CoinClaimRequest::findOrFail($id); $claim->status='approved'; $claim->reviewed_at=now(); $claim->reviewed_by=$request->user()->id; $claim->save(); return $this->success($claim); }
    public function coinClaimReject(Request $request, string $id): JsonResponse { $v=$request->validate(['rejection_reason'=>'required|string|max:500']); $claim=CoinClaimRequest::findOrFail($id); $claim->status='rejected'; $claim->admin_remarks=$v['rejection_reason']; $claim->reviewed_at=now(); $claim->reviewed_by=$request->user()->id; $claim->save(); return $this->success($claim); }
    public function coinRules(): JsonResponse { return $this->success(['source' => 'config', 'rules' => config('coins.claim_coin', []), 'labels' => config('coins.claim_coin_labels', [])]); }
    public function coinRulesStore(Request $request): JsonResponse { return $this->success(['message' => 'Config-backed coin rules are read-only in this environment.'], 'No DB coin_rules table detected.'); }
    public function coinRulesUpdate(Request $request, string $id): JsonResponse { return $this->coinRulesStore($request); }
    public function coinRulesDelete(Request $request, string $id): JsonResponse { return $this->coinRulesStore($request); }

    // Events
    public function events(Request $request): JsonResponse { return $this->success(Event::query()->latest('start_at')->paginate(20)); }
    public function eventStore(Request $request): JsonResponse { $v=$request->validate(['title'=>'required|string|max:255','description'=>'nullable|string','circle_id'=>'nullable|uuid']); return $this->success(Event::create($v)); }
    public function eventShow(string $id): JsonResponse { return $this->success(Event::findOrFail($id)); }
    public function eventUpdate(Request $request, string $id): JsonResponse { $e=Event::findOrFail($id); $e->fill($request->only(['title','description','start_at','end_at','status']))->save(); return $this->success($e); }
    public function eventDelete(string $id): JsonResponse { Event::where('id',$id)->delete(); return $this->success(['deleted'=>true]); }
    public function eventRegistrations(string $id): JsonResponse { return $this->success(DB::table('event_rsvps')->where('event_id',$id)->paginate(50)); }
    public function eventAttendees(string $id): JsonResponse { return $this->eventRegistrations($id); }
    public function eventSpeakerStore(Request $request, string $id): JsonResponse { return $this->success(['message'=>'Speaker endpoints mapped to event metadata; no dedicated speakers table found.']); }
    public function eventSpeakerUpdate(Request $request, string $id, string $speakerId): JsonResponse { return $this->eventSpeakerStore($request, $id); }
    public function eventSpeakerDelete(Request $request, string $id, string $speakerId): JsonResponse { return $this->eventSpeakerStore($request, $id); }
    public function eventExpenseStore(Request $request, string $id): JsonResponse { $v=$request->validate(['title'=>'required|string|max:255','amount'=>'required|numeric|min:0']); DB::table('event_expenses')->insert(['id'=>(string)\Illuminate\Support\Str::uuid(),'event_id'=>$id,'title'=>$v['title'],'amount'=>$v['amount'],'created_at'=>now(),'updated_at'=>now()]); return $this->success(['created'=>true]); }
    public function eventExpenses(string $id): JsonResponse { return $this->success(DB::table('event_expenses')->where('event_id',$id)->get()); }
    public function eventSponsorshipStore(Request $request, string $id): JsonResponse { $v=$request->validate(['sponsor_name'=>'required|string|max:255','amount'=>'required|numeric|min:0']); DB::table('event_sponsors')->insert(['id'=>(string)\Illuminate\Support\Str::uuid(),'event_id'=>$id,'sponsor_name'=>$v['sponsor_name'],'amount'=>$v['amount'],'created_at'=>now(),'updated_at'=>now()]); return $this->success(['created'=>true]); }
    public function eventPnl(string $id): JsonResponse { $rev=(float) DB::table('event_sponsors')->where('event_id',$id)->sum('amount'); $exp=(float) DB::table('event_expenses')->where('event_id',$id)->sum('amount'); return $this->success(['revenue'=>$rev,'expenses'=>$exp,'net_pnl'=>$rev-$exp]); }
    public function eventApprove(string $id): JsonResponse { $e=Event::findOrFail($id); $e->status='approved'; $e->save(); return $this->success($e); }
    public function eventReject(Request $request, string $id): JsonResponse { $e=Event::findOrFail($id); $e->status='rejected'; $e->save(); return $this->success($e); }

    // Revenue/Billing
    public function payments(Request $request): JsonResponse {
        $amountColumn = $this->resolvePaymentAmountColumn();
        $categoryColumn = $this->resolvePaymentCategoryColumn();
        $q=Payment::query();
        $q->when($categoryColumn && $request->source, fn($x)=>$x->where($categoryColumn,$request->source))
            ->when($request->status, fn($x)=>$x->where('status',$request->status))
            ->when($request->user_id, fn($x)=>$x->where('user_id',$request->user_id));
        $items = $q->latest('created_at')->paginate(20);
        $items->setCollection($items->getCollection()->map(function (Payment $payment) use ($amountColumn, $categoryColumn) {
            $payment->display_amount = (float) ($payment->{$amountColumn} ?? 0);
            $payment->display_source = $categoryColumn ? (string) ($payment->{$categoryColumn} ?? '-') : null;
            return $payment;
        }));
        return $this->success($items);
    }
    public function paymentShow(string $id): JsonResponse { return $this->success(Payment::findOrFail($id)); }
    public function revenueSummary(Request $request): JsonResponse {
        $amountColumn = $this->resolvePaymentAmountColumn();
        $categoryColumn = $this->resolvePaymentCategoryColumn();
        $q=Payment::query()->whereIn('status',$this->resolvePaidStatuses());
        return $this->success([
            'total_revenue'=>(float)$q->sum($amountColumn),
            'membership_revenue'=>$categoryColumn ? (float)(clone $q)->where($categoryColumn,'membership')->sum($amountColumn) : null,
            'circle_fee_revenue'=>$categoryColumn ? (float)(clone $q)->where($categoryColumn,'circle_fee')->sum($amountColumn) : null,
            'sponsor_revenue'=>$categoryColumn ? (float)(clone $q)->where($categoryColumn,'sponsor')->sum($amountColumn) : null,
            'paid_event_revenue'=>$categoryColumn ? (float)(clone $q)->where($categoryColumn,'event')->sum($amountColumn) : null,
        ]);
    }
    public function revenueByCircle(): JsonResponse { $amountColumn = $this->resolvePaymentAmountColumn(); return $this->success(Payment::query()->whereNotNull('circle_id')->whereIn('status',$this->resolvePaidStatuses())->selectRaw("circle_id, SUM({$amountColumn}) as total")->groupBy('circle_id')->get()); }
    public function revenueByIndustry(): JsonResponse { $amountColumn = $this->resolvePaymentAmountColumn(); return $this->success(Payment::query()->whereNotNull('industry_id')->whereIn('status',$this->resolvePaidStatuses())->selectRaw("industry_id, SUM({$amountColumn}) as total")->groupBy('industry_id')->get()); }
    public function revenueByMember(): JsonResponse { $amountColumn = $this->resolvePaymentAmountColumn(); return $this->success(Payment::query()->whereIn('status',$this->resolvePaidStatuses())->selectRaw("user_id, SUM({$amountColumn}) as total")->groupBy('user_id')->get()); }
    public function revenueExport(): StreamedResponse {
        $amountColumn = $this->resolvePaymentAmountColumn();
        $categoryColumn = $this->resolvePaymentCategoryColumn();
        return $this->csvResponse('revenue-export.csv', ['id','user_id',$amountColumn,'status','category','created_at'], Payment::query()->latest('created_at')->get()->map(fn($x)=>[(string)$x->id,(string)$x->user_id,(string)($x->{$amountColumn} ?? ''),(string)$x->status,(string)($categoryColumn ? ($x->{$categoryColumn} ?? '') : ''),(string)$x->created_at])->all());
    }

    public function billingInvoices(): JsonResponse { return $this->success(Payment::query()->whereNotNull('invoice_id')->latest('created_at')->paginate(20)); }
    public function billingInvoiceShow(string $id): JsonResponse { return $this->success(Payment::query()->where('invoice_id',$id)->firstOrFail()); }
    public function billingSubscriptions(): JsonResponse { return $this->success(User::query()->whereNotNull('zoho_subscription_id')->select('id','display_name','zoho_subscription_id','zoho_plan_code','membership_status')->paginate(20)); }
    public function billingPlans(): JsonResponse { return $this->success(\App\Models\MembershipPlan::query()->paginate(20)); }
    public function billingPlanUpdate(Request $request, string $id): JsonResponse { $p=\App\Models\MembershipPlan::findOrFail($id); $p->fill($request->only(['name','price','description','is_active']))->save(); return $this->success($p); }

    // Forms
    public function leaderInterestForms(): JsonResponse { return $this->success(LeaderInterestSubmission::query()->latest('created_at')->paginate(20)); }
    public function leaderInterestFormShow(string $id): JsonResponse { return $this->success(LeaderInterestSubmission::findOrFail($id)); }
    public function leaderInterestApprove(Request $request, string $id): JsonResponse { $f=LeaderInterestSubmission::findOrFail($id); $f->status='approved'; $f->save(); return $this->success($f); }
    public function leaderInterestReject(Request $request, string $id): JsonResponse { $f=LeaderInterestSubmission::findOrFail($id); $f->status='rejected'; $f->admin_remarks=$request->input('rejection_reason'); $f->save(); return $this->success($f); }
    public function registerVisitorForms(): JsonResponse { return $this->success(VisitorRegistration::query()->with('invitedByUser')->latest('created_at')->paginate(20)); }
    public function registerVisitorFormShow(string $id): JsonResponse { return $this->success(VisitorRegistration::with('invitedByUser')->findOrFail($id)); }
    public function registerVisitorStatus(Request $request, string $id): JsonResponse { $x=VisitorRegistration::findOrFail($id); $x->status=$request->validate(['status'=>'required|string'])['status']; $x->save(); return $this->success($x); }
    public function recommendPeerForms(): JsonResponse { return $this->success(PeerRecommendation::query()->latest('created_at')->paginate(20)); }
    public function recommendPeerFormShow(string $id): JsonResponse { return $this->success(PeerRecommendation::findOrFail($id)); }
    public function recommendPeerStatus(Request $request, string $id): JsonResponse { $x=PeerRecommendation::findOrFail($id); $x->status=$request->validate(['status'=>'required|string'])['status']; $x->save(); return $this->success($x); }

    // Posts/Reports
    public function posts(Request $request): JsonResponse { return $this->success(Post::with('user:id,display_name')->latest('created_at')->paginate(20)); }
    public function postShow(string $id): JsonResponse { return $this->success(Post::with(['user','comments'])->findOrFail($id)); }
    public function postStatus(Request $request, string $id): JsonResponse { $post=Post::findOrFail($id); $post->status=$request->validate(['status'=>'required|string'])['status']; $post->save(); return $this->success($post); }
    public function postDelete(string $id): JsonResponse { Post::where('id',$id)->delete(); return $this->success(['deleted'=>true]); }
    public function postReports(): JsonResponse { return $this->success(PostReport::with(['post','user'])->latest('created_at')->paginate(20)); }
    public function postReportShow(string $id): JsonResponse { return $this->success(PostReport::with(['post','user'])->findOrFail($id)); }
    public function postReportResolve(string $id): JsonResponse { $r=PostReport::findOrFail($id); $r->status='resolved'; $r->save(); return $this->success($r); }
    public function postReportDismiss(string $id): JsonResponse { $r=PostReport::findOrFail($id); $r->status='dismissed'; $r->save(); return $this->success($r); }

    // Notification & circulars
    public function notificationLogs(): JsonResponse { return $this->success(['notifications'=>Notification::latest('created_at')->limit(100)->get(),'email_logs'=>DB::table('email_logs')->latest('created_at')->limit(100)->get()]); }
    public function notificationBroadcast(Request $request): JsonResponse { $v=$request->validate(['title'=>'required|string|max:255','message'=>'required|string']); DB::table('broadcast_messages')->insert(['id'=>(string)\Illuminate\Support\Str::uuid(),'title'=>$v['title'],'message'=>$v['message'],'created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]); return $this->success(['queued'=>true]); }
    public function notificationTemplates(): JsonResponse { return $this->success(DB::table('communication_templates')->paginate(20)); }
    public function notificationTemplateStore(Request $request): JsonResponse { $v=$request->validate(['name'=>'required|string|max:255','subject'=>'nullable|string|max:255','body'=>'required|string']); DB::table('communication_templates')->insert(['id'=>(string)\Illuminate\Support\Str::uuid(),'name'=>$v['name'],'subject'=>$v['subject'] ?? null,'body'=>$v['body'],'created_at'=>now(),'updated_at'=>now()]); return $this->success(['created'=>true]); }
    public function notificationTemplateUpdate(Request $request, string $id): JsonResponse { DB::table('communication_templates')->where('id',$id)->update(array_merge($request->only(['name','subject','body']), ['updated_at'=>now()])); return $this->success(['updated'=>true]); }
    public function circulars(): JsonResponse { return $this->success(\App\Models\Circular::query()->latest('created_at')->paginate(20)); }
    public function circularStore(Request $request): JsonResponse { $v=$request->validate(['title'=>'required|string|max:255','content'=>'required|string']); return $this->success(\App\Models\Circular::create($v)); }
    public function circularUpdate(Request $request, string $id): JsonResponse { $x=\App\Models\Circular::findOrFail($id); $x->fill($request->only(['title','content','status']))->save(); return $this->success($x); }
    public function circularDelete(string $id): JsonResponse { \App\Models\Circular::where('id',$id)->delete(); return $this->success(['deleted'=>true]); }

    // Meetings/attendance/warnings/reporting
    public function circleMeetings(string $circleId): JsonResponse { return $this->success(DB::table('circle_meetings')->where('circle_id',$circleId)->latest('meeting_date')->paginate(20)); }
    public function meetingStore(Request $request, string $circleId): JsonResponse { $v=$request->validate(['meeting_date'=>'required|date','title'=>'required|string|max:255']); DB::table('circle_meetings')->insert(['id'=>(string)\Illuminate\Support\Str::uuid(),'circle_id'=>$circleId,'meeting_date'=>$v['meeting_date'],'title'=>$v['title'],'created_at'=>now(),'updated_at'=>now()]); return $this->success(['created'=>true]); }
    public function meetingShow(string $id): JsonResponse { return $this->success(DB::table('circle_meetings')->where('id',$id)->first()); }
    public function meetingUpdate(Request $request, string $id): JsonResponse { DB::table('circle_meetings')->where('id',$id)->update(array_merge($request->only(['meeting_date','title','status']), ['updated_at'=>now()])); return $this->success(['updated'=>true]); }
    public function meetingAttendanceStore(Request $request, string $id): JsonResponse { $v=$request->validate(['user_id'=>'required|uuid|exists:users,id','status'=>'required|string']); $exists=DB::table('attendance_records')->where('meeting_id',$id)->where('user_id',$v['user_id'])->exists(); if(!$exists){ DB::table('attendance_records')->insert(['id'=>(string)\Illuminate\Support\Str::uuid(),'meeting_id'=>$id,'user_id'=>$v['user_id'],'status'=>$v['status'],'created_at'=>now(),'updated_at'=>now()]); } return $this->success(['saved'=>true]); }
    public function meetingAttendance(string $id): JsonResponse { return $this->success(DB::table('attendance_records')->where('meeting_id',$id)->get()); }
    public function attendanceUpdate(Request $request, string $id): JsonResponse { DB::table('attendance_records')->where('id',$id)->update(array_merge($request->only(['status','remarks']),['updated_at'=>now()])); return $this->success(['updated'=>true]); }
    public function meetingSubstituteStore(Request $request, string $id): JsonResponse { $v=$request->validate(['user_id'=>'required|uuid|exists:users,id','substitute_user_id'=>'required|uuid|exists:users,id']); $count=DB::table('substitute_logs')->where('meeting_id',$id)->where('user_id',$v['user_id'])->count(); abort_if($count>=3,422,'Maximum 3 substitutes reached.'); DB::table('substitute_logs')->insert(['id'=>(string)\Illuminate\Support\Str::uuid(),'meeting_id'=>$id,'user_id'=>$v['user_id'],'substitute_user_id'=>$v['substitute_user_id'],'created_at'=>now(),'updated_at'=>now()]); return $this->success(['created'=>true]); }
    public function warnings(Request $request): JsonResponse { $q=DB::table('absence_warnings'); $q->when($request->user_id, fn($x)=>$x->where('user_id',$request->user_id))->when($request->circle_id, fn($x)=>$x->where('circle_id',$request->circle_id))->when($request->filled('resolved'), fn($x)=>$x->where('resolved', filter_var($request->resolved, FILTER_VALIDATE_BOOLEAN))); return $this->success($q->latest('created_at')->paginate(20)); }
    public function warningResolve(string $id): JsonResponse { DB::table('absence_warnings')->where('id',$id)->update(['resolved'=>true,'resolved_at'=>now(),'updated_at'=>now()]); return $this->success(['resolved'=>true]); }

    public function reportsMembers(): JsonResponse { return $this->success(['total_users'=>User::count(),'active_members'=>User::where('membership_status','!=','visitor')->count()]); }
    public function reportsCircles(): JsonResponse { return $this->success(['total_circles'=>DB::table('circles')->count(),'active_circles'=>DB::table('circles')->where('status','active')->count()]); }
    public function reportsIndustries(): JsonResponse { return $this->success(DB::table('industries')->selectRaw('id, name')->get()); }
    public function reportsRevenue(): JsonResponse { return $this->revenueSummary(request()); }
    public function reportsImpacts(): JsonResponse { return $this->success(['pending'=>Impact::where('status','pending')->count(),'approved'=>Impact::where('status','approved')->count()]); }
    public function reportsEvents(): JsonResponse { return $this->success(['total'=>Event::count(),'upcoming'=>Event::whereDate('start_at','>=',now()->toDateString())->count()]); }
    public function reportsCoinClaims(): JsonResponse { return $this->success(['pending'=>CoinClaimRequest::where('status','pending')->count(),'approved'=>CoinClaimRequest::where('status','approved')->count()]); }
    public function reportsJoinRequests(): JsonResponse { return $this->success(['pending'=>CircleJoinRequest::whereIn('status',['pending_cd_approval','pending_id_approval','pending_circle_fee'])->count(),'paid'=>CircleJoinRequest::where('status','paid')->count()]); }
    public function reportsExport(Request $request): JsonResponse|StreamedResponse { $type = strtolower((string) $request->query('type', 'csv')); if ($type === 'xlsx') { return $this->error('XLSX export is not available in current environment. Use CSV.', 422); } $amountColumn = $this->resolvePaymentAmountColumn(); return $this->csvResponse('admin-report.csv', ['metric','value'], [['total_users', (string) User::count()],['total_circles',(string) DB::table('circles')->count()],['total_revenue',(string) Payment::whereIn('status',$this->resolvePaidStatuses())->sum($amountColumn)]]); }

    private function csvResponse(string $filename, array $headers, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function resolvePaymentAmountColumn(): string
    {
        foreach (['total_amount', 'amount', 'base_amount'] as $column) {
            if (Schema::hasColumn('payments', $column)) {
                return $column;
            }
        }

        return 'total_amount';
    }

    private function resolvePaymentCategoryColumn(): ?string
    {
        foreach (['source', 'type', 'payment_type', 'category', 'transaction_type', 'purpose'] as $column) {
            if (Schema::hasColumn('payments', $column)) {
                return $column;
            }
        }

        return null;
    }

    private function resolvePaidStatuses(): array
    {
        $distinct = DB::table('payments')->select('status')->whereNotNull('status')->distinct()->pluck('status')->map(fn ($s) => strtolower((string) $s))->all();
        $statuses = array_values(array_filter(['success', 'paid', 'completed'], fn ($candidate) => in_array($candidate, $distinct, true)));

        return $statuses !== [] ? $statuses : ['success'];
    }
}
