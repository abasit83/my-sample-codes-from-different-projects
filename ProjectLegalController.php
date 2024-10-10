<?php

namespace App\Http\Controllers\Api;

use Exception;
use Carbon\Carbon;
use App\Models\Family;
use App\Models\Customer;
use Illuminate\Http\Request;
use App\Models\ProjectLegal;
use App\Models\ProjectLegalFee;
use App\Models\ProjectLegalOrder;
use Illuminate\Support\Facades\DB;
use App\Models\ProjectLegalRemarks;
use App\Models\ProjectLegalInvoice;
use Illuminate\Support\Facades\Auth;
use App\Models\ProjectLegalShipping;
use App\Models\ProjectLegalAmountFell;
use App\Models\ProjectLegalRefundStatus;
use Illuminate\Support\Facades\Validator;
use App\Models\ProjectLegalIdentification;
use App\Models\ProjectLegalEngagementWorklog;
use App\Models\ProjectLegalBalanceCancellation;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Http\Requests\LegalAdvanceSearchRequest;
use App\Http\Requests\ProjectLegalUpdateRequest;
use App\Http\Requests\ProjectLegalFinancesSaveRequest;
use App\Models\ProjectLegalBalanceCancellationRemarks;
use App\Http\Requests\ProjectLegalOrderDetailsSaveRequest;
use App\Http\Controllers\Api\BaseController as BaseController;
use App\Http\Requests\ProjectLegalEngagementWorklogSaveRequest;
use App\Models\ProjectLegalBalanceCancellationBranches;
use App\Models\ProjectLegalRefundDetails;
use App\Models\User;
use Departments;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use App\Traits\HandlesErrorLogging;

class ProjectLegalController extends BaseController
{
    use HandlesErrorLogging;

    // PROJECT LISTING
    public function index(LegalAdvanceSearchRequest $request)
    {
        try {
        	$limit 		 = $request->input('limit', 50);
            $sort_type 	 = 'desc';
            $sort_column = 'project_legal.created_at';

            $search_keyword 	        = (isset($request->search_keyword) && trim($request->search_keyword) != '' ) ? trim($request->search_keyword) : '';
	        $proposal_number_char       = isset($request->proposal_number_char) ? $request->proposal_number_char : '';
	        $proposal_number            = isset($request->proposal_number) ? $request->proposal_number : '';
	        $decedent_name	            = isset($request->decedent_name) ? $request->decedent_name : '';
	        $heir_name	                = isset($request->heir_name) ? $request->heir_name : '';
	        $opportunity_status	        = isset($request->opportunity_status) ? $request->opportunity_status : '';
            $interviewer_id     	    = isset($request->interviewer_id) ? $request->interviewer_id: 0;
	        $worker_id     	            = isset($request->worker_id) ? $request->worker_id: 0;
	        $tax_officer_id     	    = isset($request->tax_officer_id) ? $request->tax_officer_id: 0;
	        $financial_officer_id     	= isset($request->financial_officer_id) ? $request->financial_officer_id: 0;
	        $project_category     	    = isset($request->project_category) ? $request->project_category : 0;
	        $progress_status     	    = isset($request->progress_status) ? $request->progress_status : '';
	        $office_id           	    = isset($request->office_id) ? $request->office_id : 0;
	        $active_tab          	    = isset($request->active_tab) ? $request->active_tab : 1;
            $adv_search                 = $request->input('adv_search',0);

            $query = ProjectLegal::with('invoice')->selectRaw("project_legal.id,project_legal.uniform_id,
                    COALESCE(NULLIF(TRIM(CONCAT(project_legal.proposal_number_char, project_legal.proposal_number)), ''), '0') as proposal_number,
                    f.customer_id,f.name as customer_name,f.family_code,f.personal_code,
                    project_legal.dec_name_kanji as decedent_name,
                    COALESCE(NULLIF(TRIM(project_legal.opportunity_status), ''), '') as opportunity_status,
                    project_legal.progress_status,
                    TO_CHAR(project_legal.order_date, 'YYYY/MM/DD') as order_date,
                    project_legal.elappsed_days,
                    TRIM(CONCAT(u.first_name, ' ', u.last_name)) as interviewer_name,
                    TRIM(CONCAT(w.first_name, ' ', w.last_name)) as worker_name,
                    TRIM(CONCAT(t.first_name, ' ', t.last_name)) as tax_officer_name,
                    TRIM(CONCAT(fo.first_name, ' ', fo.last_name)) as financial_officer_name,
                    (SELECT content FROM project_legal_engagement_worklog WHERE project_legal_id = project_legal.id AND section = 'engagement' ORDER BY id DESC LIMIT 1) AS content,
                    project_legal.real_estate_sale,
                    project_legal.will,
                    project_legal.trust,
                    project_legal.insurance,
                    CASE WHEN refund.is_deposit = 'あり' AND refund.is_refund = '完了' THEN '未返金' 
                    WHEN refund.is_deposit = 'なし' THEN 'なし'
                    WHEN refund.is_deposit = 'あり' AND refund.is_refund = '未入金' THEN '未入金'
                    ELSE ''
                    END as refund_deposit");
            
            if($active_tab == 1) {
                // $query->selectRaw("COALESCE(offices.name, '') as tax_worker_office");
                // tax_order_date : FIRST IT WAS COMING FROM PROJECT SOZOKU THEN AFTER CLIENT'S REQUIREMENT WE CHANGED AND NOW IT'S COMING FROM PROJECT LEGAL.
                $query->selectRaw("TO_CHAR(project_legal.interview_order_date, 'YYYY/MM/DD') as tax_order_date, TRIM(CONCAT(consultant_user.first_name, ' ', consultant_user.last_name)) as tax_consultant_name");
            }
            
            if($active_tab == 4) {
                $query->selectRaw("TO_CHAR(finance_data.estimated_completion_date, 'YYYY/MM/DD') as expected_billing_date, finance_data1.completion_dates_filled");
            }
            elseif($active_tab == 1 || $active_tab == 2 || $active_tab == 3 || $active_tab == 5 || $active_tab == 6) {
                $query->selectRaw("TO_CHAR((SELECT expected_billing_date FROM project_legal_order plo WHERE plo.project_legal_id = project_legal.id ORDER BY plo.id DESC LIMIT 1), 'YYYY/MM/DD') AS expected_billing_date");
                if($active_tab == 3) {
                    $query->selectRaw("TO_CHAR((SELECT response_date FROM project_legal_engagement_worklog WHERE project_legal_id = project_legal.id AND section = 'engagement' ORDER BY id DESC LIMIT 1), 'YYYY/MM/DD') as last_progress_date");
                }
            }
                    
            $query->join('families as f', function($join) {
                $join->on('project_legal.customer_id', '=', 'f.customer_id');
                $join->on('project_legal.family_codes_id', '=', 'f.family_codes_id');
            })
            ->leftJoin('users as u', 'project_legal.interviewer_id', '=', 'u.id')
            ->leftJoin('users as w', 'project_legal.worker_id', '=', 'w.id')
            ->leftJoin('users as t', 'project_legal.tax_officer_id', '=', 't.id')
            ->leftJoin('users as fo', 'project_legal.financial_officer_id', '=', 'fo.id');

            $query->leftJoin(DB::raw('LATERAL (
                SELECT project_legal_id, is_deposit, is_refund 
                FROM project_legal_refund_status 
                WHERE deleted_at IS NULL 
                AND project_legal_id = project_legal.id 
                ORDER BY id DESC 
                LIMIT 1
            ) as refund'), 'project_legal.id', '=', 'refund.project_legal_id');

            if($active_tab == 4) {
                $query->leftJoin(DB::raw('(
                            select
                            financeData.estimated_completion_date,
                            financeData.project_legal_id
                        from (
                            select
                                plbc.id,
                                plbc.project_legal_id,
                                plbcb.estimated_completion_date,
                                ROW_NUMBER() OVER (PARTITION BY plbc.project_legal_id ORDER BY plbcb.estimated_completion_date ASC) as rn
                            from
                                project_legal_balance_cancellation as plbc
                            inner join (
                                select
                                    distinct on (project_legal_balance_cancellation_id)
                                    *
                                from
                                    project_legal_balance_cancellation_branches
                                where
                                    deleted_at is null
                                    and completion_date is null
                                order by
                                    project_legal_balance_cancellation_id,
                                    estimated_completion_date asc ) as plbcb on
                                plbc.id = plbcb.project_legal_balance_cancellation_id
                        ) as financeData
                        where rn = 1
                    ) AS finance_data'), function($join) {
                    $join->on('project_legal.id', '=', 'finance_data.project_legal_id');
                });

                // When all "completion_date" are filled then display row as white. 
                $query->leftJoin(DB::raw("
                        (
                            select
                            all_projects.id as project_legal_id,
                            coalesce(
                                case
                                    when count(plbcb.id) = 0 then true
                                    when count(case when plbcb.completion_date is null then 1 end) = 0 then true
                                    when count(plbcb.id) > 0 and count(case when plbcb.completion_date is null then 1 end) = count(plbcb.id) then 'true'
                                    else false
                                end,
                                true
                            ) as completion_dates_filled
                        from
                            (select id from project_legal) as all_projects
                        left join project_legal_balance_cancellation as plbc on
                            all_projects.id = plbc.project_legal_id
                        left join project_legal_balance_cancellation_branches as plbcb on
                            plbc.id = plbcb.project_legal_balance_cancellation_id
                            and plbcb.deleted_at is null
                        group by all_projects.id
                    ) as finance_data1
                "), function($join) {
                    $join->on('project_legal.id', '=', 'finance_data1.project_legal_id');
                });
            }

            // ACTIVE TAB FILTER & JOIN
            if($active_tab == 1) {
                // $query->leftJoin('project_sozoku as psoz', function($join) {
                //     $join->on('project_legal.uniform_id', '=', 'psoz.uniform_id');
                // });
                $query->leftJoin('users as consultant_user', 'project_legal.consultant_id', '=', 'consultant_user.id');
                // $query->leftJoin('offices', 'psoz.worker_office_id', '=', 'offices.id');
            }
            elseif($active_tab == 2) {
                $query->where('project_legal.opportunity_status', '稼働中');
                // $query->whereNotNull('project_legal.interviewer_id');
                $query->where('project_legal.interviewer_id', Auth::user()->id);
            }
            elseif($active_tab == 3) {
                $query->where('project_legal.opportunity_status', '稼働中');
                // $query->whereNotNull('project_legal.worker_id');
                $query->where('project_legal.worker_id', Auth::user()->id);
            }
            elseif($active_tab == 4) {
                $query->where('project_legal.opportunity_status', '稼働中');
                // $query->whereNotNull('project_legal.financial_officer_id');
                $query->where('project_legal.financial_officer_id', Auth::user()->id);
            }
            elseif($active_tab == 5) {
                $query->where(function($q) {
                    $q->where('project_legal.real_estate_sale', '提案中');
                    $q->orWhere('project_legal.will', '提案中');
                    $q->orWhere('project_legal.trust', '提案中');
                    $q->orWhere('project_legal.insurance', '提案中');

                    $q->orWhere('project_legal.real_estate_sale', '');
                    $q->orWhere('project_legal.will', '');
                    $q->orWhere('project_legal.trust', '');
                    $q->orWhere('project_legal.insurance', '');
                });
            }
            elseif($active_tab == 6) {
                $query->where('project_legal.opportunity_status', '長期保留');
                $query->whereNotNull('project_legal.interviewer_id');
            }

            // APPLY JOIN IN RELATED TABLE
            if( !empty($heir_name) ) {
                $query->whereHas('heirs', function($q) use($heir_name) {
                    $q->where('families.name', 'LIKE', "%$heir_name%");
                    $q->orWhere('families.name_kana', 'LIKE', "%$heir_name%");
                });
            }

            // APPLY FILTERS
            if($search_keyword !== '') {
                $query->where(function($q) use($search_keyword) {
                    $search_keyword = strtolower($search_keyword);
                    $q->where(DB::raw('LOWER(f.personal_code)'), $search_keyword);
                    $q->orWhere(DB::raw('LOWER(f.family_code)'), $search_keyword);
                    $q->orWhere('project_legal.uniform_id', $search_keyword);

                    // if (is_numeric($search_keyword) && $search_keyword <= 2147483647) {     // strlen, to avoid "Numeric value out of range" error
                    // }
                });
            }

            if($decedent_name !== '') {
                $query->where(function($q) use($decedent_name) {
                    $decedent_name = str_replace('　', ' ', $decedent_name);
                    $q->where(DB::raw("REGEXP_REPLACE(project_legal.dec_name_kana::text, '[[:space:]]+', ' ')"), 'LIKE', "%$decedent_name%");
                    $q->orWhere(DB::raw("REGEXP_REPLACE(project_legal.dec_name_kanji::text, '[[:space:]]+', ' ')"), 'LIKE', "%$decedent_name%");
                });
            }

            if ($proposal_number_char != '') {
                $query->where(DB::raw('TRIM(project_legal.proposal_number_char)'), $proposal_number_char);
            }
            if ($proposal_number != '') {
                $query->where(DB::raw('TRIM(project_legal.proposal_number)'), $proposal_number);
            }
            
            if ($progress_status != '') {
                $query->where('project_legal.progress_status', $progress_status);
            }

            if ($office_id != 0) {
                $query->where('project_legal.office_id', $office_id);
            }

            if ($interviewer_id != 0) {
                $query->where('u.id', $interviewer_id);
            }
            if ($worker_id != 0) {
                $query->where('w.id', $worker_id);
            }
            if ($tax_officer_id != 0) {
                $query->where('t.id', $tax_officer_id);
            }
            if ($financial_officer_id != 0) {
                $query->where('fo.id', $financial_officer_id);
            }
            
            if ($opportunity_status != '') {
                $query->where('project_legal.opportunity_status', $opportunity_status);
            }

            if ($project_category != 0) {
                $query->where(function ($query) use ($project_category) {
                    $query->whereRaw("project_legal.project_category @> ?", ['[' . $project_category . ']'])
                          ->orWhereRaw("project_legal.project_category @> ?", [json_encode([(string) $project_category])]);
                });
            }

            // Call to Advance Search method
            if ($adv_search == 1) {
                $query = $this->advanceSearchFilter($request, $query);
            }

            // SORTING COLUMNS
            $sort_column_array = [
                "id" => 'project_legal.uniform_id',
                "proposalNumber" => 'proposal_number',
                "customerName" => 'f.name_kana',
                "decedentName" => 'project_legal.dec_name_kanji',
                "opportunityStatus" => 'opportunityStatus',
                "orderDate" => 'orderDate',
                "taxOrderDate" => 'taxOrderDate',
                "taxConsultantName" => 'consultant_user.first_name',
                "elapsedDays" => 'project_legal.elappsed_days',
                "expectedBillingDate" => 'expectedBillingDate',
                "interviewerName" => 'u.first_name',
                "workerName" => 'w.first_name',
                "taxOfficerName" => 't.first_name',
                "financialOfficerName" => 'fo.first_name',
                "content" => 'content',
                "paymentStatus" => 'payment_status',
                "refundDeposit" => 'refund.is_deposit',
                "progressStatus" => 'project_legal.progress_status',
                "lastProgressDate" => 'lastProgressDate',
                "realEstateSale" => 'project_legal.real_estate_sale',
                "will" => 'project_legal.will',
                "trust" => 'project_legal.trust',
                "insurance" => 'project_legal.insurance',
                // "taxWorkerOffice" => 'offices.id',
            ];
            if (isset($request->sort_asc) && $request->sort_asc != '') {
                $sort_type = 'asc';
                $sort_column = $sort_column_array[$request->sort_asc];
            }
            if (isset($request->sort_desc) && $request->sort_desc != '') {
                $sort_type = 'desc';
                $sort_column = $sort_column_array[$request->sort_desc];
            }

            if($sort_column == 'opportunityStatus') {
                $_opportunity_status_case = '';
                $_count = 1;
                foreach(opportunityStatusDD() as $os) {
                    $_opportunity_status_case .= "WHEN '" . $os . "' THEN " . $_count . " ";
                    $_count++;
                }
                $_opportunity_status_case .= "ELSE " . $_count;
                $query->orderByRaw("
                    CASE opportunity_status
                        $_opportunity_status_case
                    END $sort_type
                ");
            }
            elseif($sort_column == 'orderDate') {
                if($sort_type == 'asc') {
                    $query->orderBy(DB::raw("CASE WHEN project_legal.order_date IS NULL THEN 0 ELSE 1 END, project_legal.order_date"), $sort_type);   // WE HAVE TO SHOW EMPTY DATES ON TOP
                }
                if($sort_type == 'desc') {
                    $query->orderBy(DB::raw("CASE WHEN project_legal.order_date IS NULL THEN 1 ELSE 0 END, project_legal.order_date"), $sort_type);   // WE HAVE TO SHOW EMPTY DATES ON BOTTOM
                }
            }
            elseif($sort_column == 'taxOrderDate') {
                if($sort_type == 'asc') {
                    $query->orderBy(DB::raw("CASE WHEN psoz.interview_order_date IS NULL THEN 0 ELSE 1 END, psoz.interview_order_date"), $sort_type);   // WE HAVE TO SHOW EMPTY DATES ON TOP
                }
                if($sort_type == 'desc') {
                    $query->orderBy(DB::raw("CASE WHEN psoz.interview_order_date IS NULL THEN 1 ELSE 0 END, psoz.interview_order_date"), $sort_type);   // WE HAVE TO SHOW EMPTY DATES ON BOTTOM
                }
            }
            elseif($sort_column == 'expectedBillingDate') {
                if($sort_type == 'asc') {
                    $query->orderBy(DB::raw("CASE WHEN (SELECT expected_billing_date FROM project_legal_order plo WHERE plo.project_legal_id = project_legal.id ORDER BY plo.id DESC LIMIT 1) IS NULL THEN 0 ELSE 1 END, (SELECT expected_billing_date FROM project_legal_order plo WHERE plo.project_legal_id = project_legal.id ORDER BY plo.id DESC LIMIT 1)"), $sort_type);   // WE HAVE TO SHOW EMPTY DATES ON TOP
                }
                if($sort_type == 'desc') {
                    $query->orderBy(DB::raw("CASE WHEN (SELECT expected_billing_date FROM project_legal_order plo WHERE plo.project_legal_id = project_legal.id ORDER BY plo.id DESC LIMIT 1) IS NULL THEN 1 ELSE 0 END, (SELECT expected_billing_date FROM project_legal_order plo WHERE plo.project_legal_id = project_legal.id ORDER BY plo.id DESC LIMIT 1)"), $sort_type);   // WE HAVE TO SHOW EMPTY DATES ON BOTTOM
                }
            }
            elseif($sort_column == 'lastProgressDate') {
                if($sort_type == 'asc') {
                    $query->orderBy(DB::raw("CASE WHEN (SELECT response_date FROM project_legal_engagement_worklog WHERE project_legal_id = project_legal.id AND section = 'engagement' ORDER BY id DESC LIMIT 1) IS NULL THEN 0 ELSE 1 END, (SELECT response_date FROM project_legal_engagement_worklog WHERE project_legal_id = project_legal.id AND section = 'engagement' ORDER BY id DESC LIMIT 1)"), $sort_type);   // WE HAVE TO SHOW EMPTY DATES ON TOP
                }
                if($sort_type == 'desc') {
                    $query->orderBy(DB::raw("CASE WHEN (SELECT response_date FROM project_legal_engagement_worklog WHERE project_legal_id = project_legal.id AND section = 'engagement' ORDER BY id DESC LIMIT 1) IS NULL THEN 1 ELSE 0 END, (SELECT response_date FROM project_legal_engagement_worklog WHERE project_legal_id = project_legal.id AND section = 'engagement' ORDER BY id DESC LIMIT 1)"), $sort_type);   // WE HAVE TO SHOW EMPTY DATES ON BOTTOM
                }
            }
            elseif($sort_column != 'payment_status' && $sort_column != 'opportunityStatus') {
                $query->orderBy($sort_column, $sort_type);
            }

            // echo $query->toSql(); exit;
            $projects = $query->paginate($limit);

            $projects->getCollection()->transform(function ($project_legal) {
                $unpaidInvoices = $project_legal->invoice->whereNull('payment_date')->count();
                $paidInvoices = $project_legal->invoice->whereNotNull('payment_date')->count();
            
                if ($unpaidInvoices > 0) {
                    $project_legal->payment_status = '未入金';          // '未入金' == UNPAID
                } elseif ($paidInvoices == $project_legal->invoice->count() && $paidInvoices > 0) {
                    $project_legal->payment_status = '入金済み';        // '入金済み' == DEPOSITED
                } else {
                    $project_legal->payment_status = '';
                }
            
                return $project_legal;
            });

            // HAVE TO DO R&D ON BELOW COMMENTED SECTION.
            // Sort the collection by payment_status
            /* if($sort_column == 'payment_status') {
                if($sort_type == 'asc') {
                    $sorted_projects = $projects->getCollection()->sortBy('payment_status');
                }
                elseif($sort_type == 'desc') {
                    $sorted_projects = $projects->getCollection()->sortByDesc('payment_status');
                }

                // Create a new paginated collection after sorting
                $projects = new LengthAwarePaginator(
                    $sorted_projects->forPage($projects->currentPage(), $limit),
                    $sorted_projects->count(),
                    $limit,
                    $projects->currentPage(),
                    ['path' => request()->url()]
                );
            } */

	        if (count($projects)) {
	            return $this->sendResponse($projects, __('record_found'));
	        }

	        return $this->sendResponse([], __('record_not_found'));
	    } 
	    catch (Exception $e) {
	        return $this->sendError(__('something_went_wrong'), [$e->getMessage()], 400);
	    }
    }

    // ADVANCE SEARCH
    private function advanceSearchFilter($request, $query)
    {
        $cust_dob_start_date                     = $request->input('cust_dob_start_date', '');
        $cust_dob_end_date                       = $request->input('cust_dob_end_date', '');
        $cust_address                            = $request->input('cust_address', '');
        $pl_inheritance_start_date               = $request->input('pl_inheritance_start_date', '');
        $pl_inheritance_end_date                 = $request->input('pl_inheritance_end_date', '');

        $pl_heir_is_representative               = $request->input('pl_heir_is_representative',0);
        $pl_heir_relation                        = $request->input('pl_heir_relation', '');
        $pl_heir_telephone                       = $request->input('pl_heir_telephone', '');
        $pl_heir_email                           = $request->input('pl_heir_email', '');
        $pl_heir_zipcode                         = $request->input('pl_heir_zipcode', '');
        $pl_heir_prefecture                      = $request->input('pl_heir_prefecture', '');
        $pl_heir_address                         = $request->input('pl_heir_address', '');

        $pl_opportunity_status                   = $request->input('pl_opportunity_status', '');
        $pl_project_category                     = $request->input('pl_project_category', '');
        $pl_office                               = $request->input('pl_office', '');
        $pl_introduced_by                        = $request->input('pl_introduced_by', '');
        $pl_tax_officer                          = $request->input('pl_tax_officer', '');
        $pl_tax_worker                           = $request->input('pl_tax_worker', '');
        $pl_office_corporate_tossers             = $request->input('pl_office_corporate_tossers', '');
        $pl_corporate_tossers                    = $request->input('pl_corporate_tossers', '');
        $pl_order_start_date                     = $request->input('pl_order_start_date', '');
        $pl_order_end_date                       = $request->input('pl_order_end_date', '');
        $pl_total_order_start_amount             = $request->input('pl_total_order_start_amount', '');
        $pl_total_order_end_amount               = $request->input('pl_total_order_end_amount', '');
        $pl_billing_total_start_amount           = $request->input('pl_billing_total_start_amount', '');
        $pl_billing_total_end_amount             = $request->input('pl_billing_total_end_amount', '');
        $pl_due_date                             = $request->input('pl_due_date', '');
        $pl_progress_status                      = $request->input('pl_progress_status', '');
        $pl_real_estate_appraisal                = $request->input('pl_real_estate_appraisal',0);
        $pl_real_estate_sale                     = $request->input('pl_real_estate_sale', '');
        $pl_will                                 = $request->input('pl_will', '');
        $pl_trust                                = $request->input('pl_trust', '');
        $pl_insurance                            = $request->input('pl_insurance', '');
        $pl_newsletter                           = $request->input('pl_newsletter', '');
        $pl_execution                            = $request->input('pl_execution', '');
        $pl_execution_start_amount               = $request->input('pl_execution_start_amount', '');
        $pl_execution_end_amount                 = $request->input('pl_execution_end_amount', '');
        $pl_custody_of_will                      = $request->input('pl_custody_of_will', '');
        $pl_age_of_testator_start_date           = $request->input('pl_age_of_testator_start_date', '');
        $pl_age_of_testator_end_date             = $request->input('pl_age_of_testator_end_date', '');
        $pl_executed                             = $request->input('pl_executed',0, '');
        $pl_tax_return                           = $request->input('pl_tax_return', '');

        $pl_order_start_date_start               = $request->input('pl_order_start_date_start', '');
        $pl_order_start_date_end                 = $request->input('pl_order_start_date_end', '');
        $pl_order_work_completion_start_date     = $request->input('pl_order_work_completion_start_date', '');
        $pl_order_work_completion_end_date       = $request->input('pl_order_work_completion_end_date', '');
        $pl_order_check_start_date               = $request->input('pl_order_check_start_date', '');
        $pl_order_check_end_date                 = $request->input('pl_order_check_end_date', '');
        $pl_order_inspector                      = $request->input('pl_order_inspector', '');
        $pl_order_expected_billing_start_date    = $request->input('pl_order_expected_billing_start_date', '');
        $pl_order_expected_billing_end_date      = $request->input('pl_order_expected_billing_end_date', '');
        $pl_order_billing_start_date             = $request->input('pl_order_billing_start_date', '');
        $pl_order_billing_end_date               = $request->input('pl_order_billing_end_date', '');

        $pl_amount_fell_start_date               = $request->input('pl_amount_fell_start_date', '');
        $pl_amount_fell_end_date                 = $request->input('pl_amount_fell_end_date', '');
        $pl_amount_fell_start_amount             = $request->input('pl_amount_fell_start_amount', '');
        $pl_amount_fell_end_amount               = $request->input('pl_amount_fell_end_amount', '');

        $pl_invoice_billing_start_date           = $request->input('pl_invoice_billing_start_date', '');
        $pl_invoice_billing_end_date             = $request->input('pl_invoice_billing_end_date', '');
        $pl_invoice_deposit_status               = $request->input('pl_invoice_deposit_status', '');

        $pl_worklog_compatible_start_date         = $request->input('pl_worklog_compatible_start_date', '');
        $pl_worklog_compatible_end_date           = $request->input('pl_worklog_compatible_end_date', '');
        $pl_worklog_corresponding_person          = $request->input('pl_worklog_corresponding_person', '');

        $pl_engagement_compatible_start_date      = $request->input('pl_engagement_compatible_start_date', '');
        $pl_engagement_compatible_end_date        = $request->input('pl_engagement_compatible_end_date', '');
        $pl_engagement_corresponding_person       = $request->input('pl_engagement_corresponding_person', '');

        $pl_identity_contact_start_date           = $request->input('pl_identity_contact_start_date', '');
        $pl_identity_contact_end_date             = $request->input('pl_identity_contact_end_date', '');
        $pl_identity_verify_person                = $request->input('pl_identity_verify_person', '');

        $pl_lg_affair_application_start_date      = $request->input('pl_lg_affair_application_start_date', '');
        $pl_lg_affair_application_end_date        = $request->input('pl_lg_affair_application_end_date', '');
        $pl_lg_affair_jurisdiction                = $request->input('pl_lg_affair_jurisdiction', '');
        $pl_lg_affair_expected_comp_start_date    = $request->input('pl_lg_affair_expected_comp_start_date', '');
        $pl_lg_affair_expected_comp_end_date      = $request->input('pl_lg_affair_expected_comp_end_date', '');

        $pl_family_court_application_start_date      = $request->input('pl_family_court_application_start_date', '');
        $pl_family_court_application_end_date        = $request->input('pl_family_court_application_end_date', '');
        $pl_family_court_jurisdiction                = $request->input('pl_family_court_jurisdiction', '');

        $pl_notes                                    = $request->input('pl_notes', '');
        $pl_remarks                                  = $request->input('pl_remarks', '');
        

        if ( !empty($pl_heir_is_representative) || !empty($pl_heir_relation) || !empty($pl_heir_telephone) || !empty($pl_heir_email) || !empty($pl_heir_zipcode) || !empty($pl_heir_prefecture) || !empty($pl_heir_address)) {
            $query->join('project_legal_heir', 'project_legal_heir.project_legal_id', '=', 'project_legal.id')
                ->join('families as frep', 'frep.id', '=', 'project_legal_heir.family_member_id');
        }

        if ( !empty($pl_order_type) || !empty($pl_order_start_date_start) || !empty($pl_order_start_date_end) || !empty($pl_order_work_completion_start_date) || !empty($pl_order_work_completion_end_date) || !empty($pl_order_check_start_date) || !empty($pl_order_check_end_date) || !empty($pl_order_inspector) || !empty($pl_order_expected_billing_start_date) || !empty($pl_order_expected_billing_end_date) || !empty($pl_order_billing_start_date) || !empty($pl_order_billing_end_date) ) {
            $query->join('project_legal_order as pl_order', 'pl_order.project_legal_id', '=', 'project_legal.id');
        }

        if ( !empty($pl_amount_fell_start_date) || !empty($pl_amount_fell_end_date) || !empty($pl_amount_fell_start_amount) || !empty($pl_amount_fell_end_amount)) {
            $query->join('project_legal_amount_fell as pl_amount_fell', 'pl_amount_fell.project_legal_id', '=', 'project_legal.id');
        }

        if ( !empty($pl_invoice_billing_start_date) || !empty($pl_invoice_billing_end_date) || !empty($pl_invoice_deposit_status) ) {
            $query->join('project_legal_invoice as pl_invoice', 'pl_invoice.project_legal_id', '=', 'project_legal.id');
        }

        if (!empty($pl_engagement_compatible_start_date) || !empty($pl_engagement_compatible_end_date) || !empty($pl_engagement_corresponding_person) ) {
            $query->join('project_legal_engagement_worklog as plengagement', function ($join) {
                $join->on('project_legal.id', '=', 'plengagement.project_legal_id')
                    ->where('plengagement.section', 'engagement');
            });
        }

        if (!empty($pl_worklog_compatible_start_date) || !empty($pl_worklog_compatible_end_date) || !empty($pl_worklog_corresponding_person) ) {
            $query->join('project_legal_engagement_worklog as plworklog', function ($join) {
                $join->on('project_legal.id', '=', 'plworklog.project_legal_id')
                    ->where('plworklog.section', 'worklog');
            });
        }

        if ( !empty($pl_identity_contact_start_date) || !empty($pl_identity_contact_end_date) || !empty($pl_identity_verify_person) ) {
            $query->join('project_legal_identification as pl_identification', 'pl_identification.project_legal_id', '=', 'project_legal.id');
        }

        if (!empty($pl_lg_affair_application_start_date) || !empty($pl_lg_affair_application_end_date) || !empty($pl_lg_affair_jurisdiction) || !empty($pl_lg_affair_expected_comp_start_date) || !empty($pl_lg_affair_expected_comp_end_date) ) {
            $query->join('project_legal_shipping as pl_legal_shipping', function ($join) {
                $join->on('project_legal.id', '=', 'pl_legal_shipping.project_legal_id')
                    ->where('pl_legal_shipping.shipping_type', 'legal');
            });
        }

        if (!empty($pl_family_court_application_start_date) || !empty($pl_family_court_application_end_date) || !empty($pl_family_court_jurisdiction) ) {
            $query->join('project_legal_shipping as pl_family_shipping', function ($join) {
                $join->on('project_legal.id', '=', 'pl_family_shipping.project_legal_id')
                    ->where('pl_family_shipping.shipping_type', 'family');
            });
        }

        if ( !empty($pl_remarks) ) {
            $query->join('project_legal_remarks as pl_remarks', 'pl_remarks.project_legal_id', '=', 'project_legal.id');
        }

        // Conditions to filter based on the date of birth range
        if (!empty($cust_dob_start_date) && !empty($cust_dob_end_date)) {
            $query->whereBetween('f.dob', [$cust_dob_start_date, $cust_dob_end_date]);
        } elseif (!empty($cust_dob_start_date)) {
            $query->where('f.dob', '>=', $cust_dob_start_date);
        } elseif (!empty($cust_dob_end_date)) {
            $query->where('f.dob', '<=', $cust_dob_end_date);
        }

        // Condition to filter based on the address
        if (!empty($cust_address)) {
            $query->where('f.address', 'LIKE', "%$cust_address%");
        }

        // Conditions to filter based on the dec_inheritance_start_date range
        if (!empty($pl_inheritance_start_date) && !empty($pl_inheritance_end_date)) {
            $query->whereBetween('project_legal.dec_inheritance_start_date', [$pl_inheritance_start_date, $pl_inheritance_end_date]);
        } elseif (!empty($pl_inheritance_start_date)) {
            $query->where('project_legal.dec_inheritance_start_date', '>=', $pl_inheritance_start_date);
        } elseif (!empty($pl_inheritance_end_date)) {
            $query->where('project_legal.dec_inheritance_start_date', '<=', $pl_inheritance_end_date);
        }

        // Condition to filter based on the project legal heir is_representative
        if ($pl_heir_is_representative == 1) {
            $query->where('project_legal_heir.is_representative', 1);
        }

        // Condition to filter based on the project legal heir relation
        if (!empty($pl_heir_relation)) {
            $query->where('frep.relation', 'LIKE', "%$pl_heir_relation%");
        }

        // Condition to filter based on the project legal heir telephone
        if (!empty($pl_heir_telephone)) {
            $query->where(function ($q) use ($pl_heir_telephone) {
                $q->where('frep.phone1', 'LIKE', "%$pl_heir_telephone%")
                    ->orWhere('frep.phone2', 'LIKE', "%$pl_heir_telephone%")
                    ->orWhere('frep.phone3', 'LIKE', "%$pl_heir_telephone%")
                    ->orWhere(DB::raw('REPLACE(frep.phone1, \'-\', \'\')'), 'LIKE', "%$pl_heir_telephone%")
                    ->orWhere(DB::raw('REPLACE(frep.phone2, \'-\', \'\')'), 'LIKE', "%$pl_heir_telephone%")
                    ->orWhere(DB::raw('REPLACE(frep.phone3, \'-\', \'\')'), 'LIKE', "%$pl_heir_telephone%");
            });
        }

        // Condition to filter based on the project legal heir email
        if (!empty($pl_heir_email)) {
            $query->where(function ($q) use ($pl_heir_email) {
                $q->where('frep.email', 'LIKE', "%$pl_heir_email%")
                    ->orWhere('frep.email2', 'LIKE', "%$pl_heir_email%");
            });
        }

        // Condition to filter based on the project legal heir zipcode
        if (!empty($pl_heir_zipcode)) {
            $query->where('frep.zipcode','LIKE', "%$pl_heir_zipcode%");
        }

        // Condition to filter based on the project legal heir prefecture_id
        if (!empty($pl_heir_prefecture)) {
            $query->where('frep.prefecture_id', $pl_heir_prefecture);
        }

        // Condition to filter based on the project legal heir address
        if (!empty($pl_heir_address)) {
            $query->where('frep.address', 'LIKE', "%$pl_heir_address%");
        } 
        
        // Condition to filter based on the project legal opportunity_status
        if (!empty($pl_opportunity_status)) {
            $query->where('project_legal.opportunity_status', $pl_opportunity_status);
        }

        if (!empty($pl_project_category)) {
            // Check if the input is numeric
            if (is_numeric($pl_project_category)) {
                // Convert the input to an integer for integer-specific queries
                $pl_project_category_int = (int)$pl_project_category;
                
                // Use parameterized query to avoid SQL injection
                $query->whereRaw('project_legal.project_category @> ?::jsonb OR project_legal.project_category @> ?::jsonb', [
                    json_encode([$pl_project_category_int]), // Integer JSON format
                    json_encode([$pl_project_category]), // String JSON format
                ]);
            } 
            else {
                // Handle invalid input (e.g., return an error or an empty response)
                abort(400, 'Invalid project category input');
            }
        }                

        // Condition to filter based on the project legal office_id
        if (!empty($pl_office)) {
            $query->where('project_legal.office_id', $pl_office);
        }

        // Condition to filter based on the project legal introduced_by_id
        if (!empty($pl_introduced_by)) {
            $query->where('project_legal.introduced_by_id', $pl_introduced_by);
        }

        // Condition to filter based on the project legal tax_officer_id
        if (!empty($pl_tax_officer)) {
            $query->where('project_legal.tax_officer_id', $pl_tax_officer);
        }

        // Condition to filter based on the project legal tax_worker_id
        if (!empty($pl_tax_worker)) {
            $query->where('project_legal.tax_worker_id', $pl_tax_worker);
        }

        // Condition to filter based on the project legal office_corporate_tossers
        if (!empty($pl_office_corporate_tossers)) {
            $query->where('project_legal.office_corporate_tossers', $pl_office_corporate_tossers);
        }

        // Condition to filter based on the project legal corporate_tossers
        if (!empty($pl_corporate_tossers)) {
            $query->where('project_legal.corporate_tossors', 'LIKE', "%$pl_corporate_tossers%");
        }       

        // Conditions to filter based on the project legal order_date range
        if (!empty($pl_order_start_date) && !empty($pl_order_end_date)) {
            $query->whereBetween('project_legal.order_date', [$pl_order_start_date, $pl_order_end_date]);
        } elseif (!empty($pl_order_start_date)) {
            $query->where('project_legal.order_date', '>=', $pl_order_start_date);
        } elseif (!empty($pl_order_end_date)) {
            $query->where('project_legal.order_date', '<=', $pl_order_end_date);
        }

        // Conditions to filter based on the project legal order_date days range
        if (!empty($pl_elapsed_start_days) && !empty($pl_elapsed_end_days)) {
            $query->whereBetween(DB::raw("DATE_PART('day', NOW() - project_legal.order_date )"), [$pl_elapsed_start_days, $pl_elapsed_end_days]);
        } elseif (!empty($pl_elapsed_start_days)) {
            $query->where(DB::raw("DATE_PART('day', NOW() - project_legal.order_date )"), '>=', $pl_elapsed_start_days);
        } elseif (!empty($pl_elapsed_end_days)) {
            $query->where(DB::raw("DATE_PART('day', NOW() - project_legal.order_date )"), '<=', $pl_elapsed_end_days);
        }

        // Conditions to filter based on the project legal order_amount range
        if (!empty($pl_total_order_start_amount) && !empty($pl_total_order_end_amount)) {
            $query->whereBetween('project_legal.order_amount', [$pl_total_order_start_amount, $pl_total_order_end_amount]);
        } elseif (!empty($pl_total_order_start_amount)) {
            $query->where('project_legal.order_amount', '>=', $pl_total_order_start_amount);
        } elseif (!empty($pl_total_order_end_amount)) {
            $query->where('project_legal.order_amount', '<=', $pl_total_order_end_amount);
        }

        // Conditions to filter based on the project legal billing_total range
        if (!empty($pl_billing_total_start_amount) && !empty($pl_billing_total_end_amount)) {
            $query->whereBetween('project_legal.billing_total', [$pl_billing_total_start_amount, $pl_billing_total_end_amount]);
        } elseif (!empty($pl_billing_total_start_amount)) {
            $query->where('project_legal.billing_total', '>=', $pl_billing_total_start_amount);
        } elseif (!empty($pl_billing_total_end_amount)) {
            $query->where('project_legal.billing_total', '<=', $pl_billing_total_end_amount);
        }

        // Condition to filter based on the project legal due_date
        /* if (!empty($pl_due_date)) {
            $query->where('project_legal.due_date', $pl_due_date);
        } */

        // Condition to filter based on the project legal progress_status
        if (!empty($pl_progress_status)) {
            $query->where('project_legal.progress_status', $pl_progress_status);
        }

        // Condition to filter based on the project legal real_estate_appraisal
        if ($pl_real_estate_appraisal == 1) {
            $query->where('project_legal.real_estate_appraisal', 1);
        }

        // Condition to filter based on the project legal real_estate_sale
        if (!empty($pl_real_estate_sale)) {
            $query->where('project_legal.real_estate_sale', $pl_real_estate_sale);
        }

        // Condition to filter based on the project legal will
        if (!empty($pl_will)) {
            $query->where('project_legal.will', $pl_will);
        }

        // Condition to filter based on the project legal trust
        if (!empty($pl_trust)) {
            $query->where('project_legal.trust', $pl_trust);
        }

        // Condition to filter based on the project legal insurance
        if (!empty($pl_insurance)) {
            $query->where('project_legal.insurance', $pl_insurance);
        }

        // Condition to filter based on the project legal newsletter
        if (!empty($pl_newsletter)) {
            $query->where('project_legal.newsletter', $pl_newsletter);
        }

        // Condition to filter based on the project legal execution
        if (!empty($pl_execution)) {
            $query->where('project_legal.execution', $pl_execution);
        }

        // Conditions to filter based on the project legal execution_fee range
        if (!empty($pl_execution_start_amount) && !empty($pl_execution_end_amount)) {
            $query->whereBetween('project_legal.execution_fee', [$pl_execution_start_amount, $pl_execution_end_amount]);
        } elseif (!empty($pl_execution_start_amount)) {
            $query->where('project_legal.execution_fee', '>=', $pl_execution_start_amount);
        } elseif (!empty($pl_execution_end_amount)) {
            $query->where('project_legal.execution_fee', '<=', $pl_execution_end_amount);
        }

        // Condition to filter based on the project legal custody_of_will
        if (!empty($pl_will_custody)) {
            $query->where('project_legal.custody_of_will', $pl_will_custody);
        }

        // Conditions to filter based on the project legal age_of_testator date range
        if (!empty($pl_age_of_testator_start_date) && !empty($pl_age_of_testator_end_date)) {
            $query->whereBetween('project_legal.age_of_testator', [$pl_age_of_testator_start_date, $pl_age_of_testator_end_date]);
        } elseif (!empty($pl_age_of_testator_start_date)) {
            $query->where('project_legal.age_of_testator', '>=', $pl_age_of_testator_start_date);
        } elseif (!empty($pl_age_of_testator_end_date)) {
            $query->where('project_legal.age_of_testator', '<=', $pl_age_of_testator_end_date);
        }

        // Condition to filter based on the project legal executed
        if ($pl_executed == 1) {
            $query->where('project_legal.executed', 1);
        }

        // Condition to filter based on the project legal tax_return
        if (!empty($pl_tax_return)) {
            $query->where('project_legal.tax_return', $pl_tax_return);
        }

        // Condition to filter based on the project legal order_type
        if (!empty($pl_order_type)) {
            $query->where('pl_order.order_type', $pl_order_type);
        }

        // Conditions to filter based on the project legal order start_date range
        if (!empty($pl_order_start_date_start) && !empty($pl_order_start_date_end)) {
            $query->whereBetween('pl_order.start_date', [$pl_order_start_date_start, $pl_order_start_date_end]);
        } elseif (!empty($pl_order_start_date_start)) {
            $query->where('pl_order.start_date', '>=', $pl_order_start_date_start);
        } elseif (!empty($pl_order_start_date_end)) {
            $query->where('pl_order.start_date', '<=', $pl_order_start_date_end);
        }

        // Conditions to filter based on the project legal order work_completion_date range
        if (!empty($pl_order_work_completion_start_date) && !empty($pl_order_work_completion_end_date)) {
            $query->whereBetween('pl_order.work_completion_date', [$pl_order_work_completion_start_date, $pl_order_work_completion_end_date]);
        } elseif (!empty($pl_order_work_completion_start_date)) {
            $query->where('pl_order.work_completion_date', '>=', $pl_order_work_completion_start_date);
        } elseif (!empty($pl_order_work_completion_end_date)) {
            $query->where('pl_order.work_completion_date', '<=', $pl_order_work_completion_end_date);
        }

        // Conditions to filter based on the project legal order check_date range
        if (!empty($pl_order_check_start_date) && !empty($pl_order_check_end_date)) {
            $query->whereBetween('pl_order.check_date', [$pl_order_check_start_date, $pl_order_check_end_date]);
        } elseif (!empty($pl_order_check_start_date)) {
            $query->where('pl_order.check_date', '>=', $pl_order_check_start_date);
        } elseif (!empty($pl_order_check_end_date)) {
            $query->where('pl_order.check_date', '<=', $pl_order_check_end_date);
        }

        // Condition to filter based on the inspector_id
        if (!empty($pl_order_inspector)) {
            $query->where('pl_order.inspector_id', $pl_order_inspector);
        }

        // Conditions to filter based on the project legal order expected_billing_date range
        if (!empty($pl_order_expected_billing_start_date) && !empty($pl_order_expected_billing_end_date)) {
            $query->whereBetween('pl_order.expected_billing_date', [$pl_order_expected_billing_start_date, $pl_order_expected_billing_end_date]);
        } elseif (!empty($pl_order_expected_billing_start_date)) {
            $query->where('pl_order.expected_billing_date', '>=', $pl_order_expected_billing_start_date);
        } elseif (!empty($pl_order_expected_billing_end_date)) {
            $query->where('pl_order.expected_billing_date', '<=', $pl_order_expected_billing_end_date);
        }

        // Conditions to filter based on the project legal order billing_date range
        if (!empty($pl_order_billing_start_date) && !empty($pl_order_billing_end_date)) {
            $query->whereBetween('pl_order.billing_date', [$pl_order_billing_start_date, $pl_order_billing_end_date]);
        } elseif (!empty($pl_order_billing_start_date)) {
            $query->where('pl_order.billing_date', '>=', $pl_order_billing_start_date);
        } elseif (!empty($pl_order_billing_end_date)) {
            $query->where('pl_order.billing_date', '<=', $pl_order_billing_end_date);
        }

        // Conditions to filter based on the project legal fallen amount date range
        if (!empty($pl_amount_fell_start_date) && !empty($pl_amount_fell_end_date)) {
            $query->whereBetween('pl_amount_fell.date', [$pl_amount_fell_start_date, $pl_amount_fell_end_date]);
        } elseif (!empty($pl_amount_fell_start_date)) {
            $query->where('pl_amount_fell.date', '>=', $pl_amount_fell_start_date);
        } elseif (!empty($pl_amount_fell_end_date)) {
            $query->where('pl_amount_fell.date', '<=', $pl_amount_fell_end_date);
        }

        // Conditions to filter based on the project legal fallen amount amount_fell range
        if (!empty($pl_amount_fell_start_amount) && !empty($pl_amount_fell_end_amount)) {
            $query->whereBetween('pl_amount_fell.amount_fell', [$pl_amount_fell_start_amount, $pl_amount_fell_end_amount]);
        } elseif (!empty($pl_amount_fell_start_amount)) {
            $query->where('pl_amount_fell.amount_fell', '>=', $pl_amount_fell_start_amount);
        } elseif (!empty($pl_amount_fell_end_amount)) {
            $query->where('pl_amount_fell.amount_fell', '<=', $pl_amount_fell_end_amount);
        }

        // Conditions to filter based on the project legal invoice invoice_date range
        if (!empty($pl_invoice_billing_start_date) && !empty($pl_invoice_billing_end_date)) {
            $query->whereBetween('pl_invoice.invoice_date', [$pl_invoice_billing_start_date, $pl_invoice_billing_end_date]);
        } elseif (!empty($pl_invoice_billing_start_date)) {
            $query->where('pl_invoice.invoice_date', '>=', $pl_invoice_billing_start_date);
        } elseif (!empty($pl_invoice_billing_end_date)) {
            $query->where('pl_invoice.invoice_date', '<=', $pl_invoice_billing_end_date);
        }

        // Conditions to filter based on the project legal invoice deposit status
        if(!empty($pl_invoice_deposit_status)) {
            if($pl_invoice_deposit_status == 'unclaimed') {      // Unclaimed
                $query->whereNull('pl_invoice.invoice_date')->whereNotNull('pl_invoice.payment_date');
            }
            elseif($pl_invoice_deposit_status == 'deposited') {    // Deposited
                $query->whereNotNull('pl_invoice.invoice_date')->whereNotNull('pl_invoice.payment_date');
            }
            elseif($pl_invoice_deposit_status == 'no_payment') {  // Not Payment
                $query->whereNotNull('pl_invoice.invoice_date')->whereNull('pl_invoice.payment_date');
            }
        }

        // Conditions to filter based on the project legal engagement response_date range
        if (!empty($pl_engagement_compatible_start_date) && !empty($pl_engagement_compatible_end_date)) {
            $query->whereBetween('plengagement.response_date', [$pl_engagement_compatible_start_date, $pl_engagement_compatible_end_date]);
        } elseif (!empty($pl_engagement_compatible_start_date)) {
            $query->where('plengagement.response_date', '>=', $pl_engagement_compatible_start_date);
        } elseif (!empty($pl_engagement_compatible_end_date)) {
            $query->where('plengagement.response_date', '<=', $pl_engagement_compatible_end_date);
        }

        // Condition to filter based on the project legal engagement corresponding_person_id
        if (!empty($pl_engagement_corresponding_person)) {
            $query->where('plengagement.corresponding_person_id', $pl_engagement_corresponding_person);
        }

        // Conditions to filter based on the project legal worklog response_date range
        if (!empty($pl_worklog_compatible_start_date) && !empty($pl_worklog_compatible_end_date)) {
            $query->whereBetween('plworklog.response_date', [$pl_worklog_compatible_start_date, $pl_worklog_compatible_end_date]);
        } elseif (!empty($pl_worklog_compatible_start_date)) {
            $query->where('plworklog.response_date', '>=', $pl_worklog_compatible_start_date);
        } elseif (!empty($pl_worklog_compatible_end_date)) {
            $query->where('plworklog.response_date', '<=', $pl_worklog_compatible_end_date);
        }

        // Condition to filter based on the project legal worklog corresponding_person_id
        if (!empty($pl_worklog_corresponding_person)) {
            $query->where('plworklog.corresponding_person_id', $pl_worklog_corresponding_person);
        }

        // Conditions to filter based on the project legal identification contact_date range
        if (!empty($pl_identity_contact_start_date) && !empty($pl_identity_contact_end_date)) {
            $query->whereBetween('pl_identification.contact_date', [$pl_identity_contact_start_date, $pl_identity_contact_end_date]);
        } elseif (!empty($pl_identity_contact_start_date)) {
            $query->where('pl_identification.contact_date', '>=', $pl_identity_contact_start_date);
        } elseif (!empty($pl_identity_contact_end_date)) {
            $query->where('pl_identification.contact_date', '<=', $pl_identity_contact_end_date);
        }

        // Condition to filter based on the project legal identification verification_responder_id
        if (!empty($pl_identity_verify_person)) {
            $query->where('pl_identification.verification_responder_id', $pl_identity_verify_person);
        }

        // Conditions to filter based on the project legal affair shipping application_date range
        if (!empty($pl_lg_affair_application_start_date) && !empty($pl_lg_affair_application_end_date)) {
            $query->whereBetween('pl_legal_shipping.application_date', [$pl_lg_affair_application_start_date, $pl_lg_affair_application_end_date]);
        } elseif (!empty($pl_lg_affair_application_start_date)) {
            $query->where('pl_legal_shipping.application_date', '>=', $pl_lg_affair_application_start_date);
        } elseif (!empty($pl_lg_affair_application_end_date)) {
            $query->where('pl_legal_shipping.application_date', '<=', $pl_lg_affair_application_end_date);
        }

        // Condition to filter based on the project legal affair shipping jurisdiction
        if (!empty($pl_lg_affair_jurisdiction)) {
            $query->where('pl_legal_shipping.jurisdiction', 'LIKE', "%$pl_lg_affair_jurisdiction%");
        }

        // Conditions to filter based on the project legal affair shipping expected_completion range
        // if (!empty($pl_lg_affair_expected_comp_start_date) && !empty($pl_lg_affair_expected_comp_end_date)) {
        //     $query->whereBetween('pl_legal_shipping.application_date', [$pl_lg_affair_expected_comp_start_date, $pl_lg_affair_expected_comp_end_date]);
        // } elseif (!empty($pl_lg_affair_expected_comp_start_date)) {
        //     $query->where('pl_legal_shipping.application_date', '>=', $pl_lg_affair_expected_comp_start_date);
        // } elseif (!empty($pl_lg_affair_expected_comp_end_date)) {
        //     $query->where('pl_legal_shipping.application_date', '<=', $pl_lg_affair_expected_comp_end_date);
        // }

        // Conditions to filter based on the project legal family court shipping application_date range
        if (!empty($pl_family_court_application_start_date) && !empty($pl_family_court_application_end_date)) {
            $query->whereBetween('pl_family_shipping.application_date', [$pl_family_court_application_start_date, $pl_family_court_application_end_date]);
        } elseif (!empty($pl_family_court_application_start_date)) {
            $query->where('pl_family_shipping.application_date', '>=', $pl_family_court_application_start_date);
        } elseif (!empty($pl_family_court_application_end_date)) {
            $query->where('pl_family_shipping.application_date', '<=', $pl_family_court_application_end_date);
        }

        // Condition to filter based on the project legal family court shipping jurisdiction
        if (!empty($pl_family_court_jurisdiction)) {
            $query->where('pl_family_shipping.jurisdiction', 'LIKE', "%$pl_family_court_jurisdiction%");
        }

        // Condition to filter based on the project legal remarks
        if (!empty($pl_notes)) {
            $query->where('project_legal.remarks', 'LIKE', "%$pl_notes%");
        }

        // Condition to filter based on the project_legal_remarks remarks
        if (!empty($pl_remarks)) {
            $query->where('pl_remarks.remarks', 'LIKE', "%$pl_remarks%");
        }

        return $query;
    }

    // SAVE BALANCE / CANCELLATION / FEE BALANCE / FEE CANCELLATION / REFUND DEPOSIT
    public function saveFinances(ProjectLegalFinancesSaveRequest $request)
    {
        try{
            DB::beginTransaction();

            $filtered_data = removeEmptyArray($request->bal_cancellation, ['type', 'branches', 'remarks']);
            $request->merge(['bal_cancellation' => array_values($filtered_data)]);
            
            $filtered_data = removeEmptyArray($request->fee, ['fee_type']);
            $request->merge(['fee' => array_values($filtered_data)]);

            // GETTING ALL EXISTING DATA FOR BALANCE CANCELLATION
            $existing_ids_bal_cancellation = ProjectLegalBalanceCancellation::where('project_legal_id', $request->project_legal_id)->pluck('id')->toArray();

            // GETTING ALL EXISTING DATA FOR FEE BALANCE/CANCELLATION
            $existing_ids_fee = ProjectLegalFee::where('project_legal_id', $request->project_legal_id)->pluck('id')->toArray();

            // CODE FOR BALANCE CANCELLATION
            if(count($request->bal_cancellation)) {
                $bal_cancel_new_data = $branches_all_data = $remarks_all_data = [];
                foreach($request->bal_cancellation as $w) {

                    if($w['id'] == '_new') {                        // NEW CASE. AS TABISH IS ADDING "_new" KEYWORD FOR NEWLY CREATED BALANCE CANCELLATION.
                        if(empty($w['bank_name']) && (empty($w['is_completed']) || $w['is_completed'] == 0)) {
                            continue;
                        }
                        $bal_cancel_new_data = [
                            'project_legal_id' => $request->project_legal_id,
                            'bank_name' => $w['bank_name'],
                            'is_completed' => empty($w['is_completed']) ? 0 : $w['is_completed'],
                            'notes' => $w['notes'],
                            'type' => $w['type'],
                            'created_by' => Auth::user()->id,
                            'updated_by' => Auth::user()->id,
                            'created_at' => date('Y-m-d H:i:s'),
                            'updated_at' => date('Y-m-d H:i:s'),
                        ];

                        $bal_cancel_obj = ProjectLegalBalanceCancellation::create($bal_cancel_new_data);

                        // NEW BRANCHES
                        if(isset($w['branches']) && count($w['branches'])) {
                            foreach($w['branches'] as $b) {
                                // Filter out empty values from the array, checking only relevant fields
                                $relevant_fields = [
                                    $b['bank_branch'],
                                    $b['contact_person_phone'],
                                    $b['content'],
                                    $b['doc_submission_date'],
                                    $b['estimated_completion_date'],
                                    $b['completion_date'],
                                    $b['commission'],
                                    $b['transfer'],
                                    $b['seal'],
                                    $b['procedure_pending']
                                ];

                                // Check if all fields are empty
                                if (!array_filter($relevant_fields)) {
                                    continue; // Skip this iteration of the loop if all fields are empty
                                }

                                ProjectLegalBalanceCancellationBranches::create([
                                    'project_legal_balance_cancellation_id' => $bal_cancel_obj->id,
                                    'bank_branch' => $b['bank_branch'],
                                    'contact_person_phone' => $b['contact_person_phone'],
                                    'content' => isset($b['content']) ? $b['content'] : NULL,
                                    'doc_submission_date' => $b['doc_submission_date'],
                                    'estimated_completion_date' => $b['estimated_completion_date'],
                                    'completion_date' => $b['completion_date'],
                                    'commission' => $b['commission'],
                                    'transfer' => $b['transfer'],
                                    'seal' => $b['seal'],
                                    'procedure_pending' => empty($b['procedure_pending']) ? 0 : $b['procedure_pending'],
                                    'created_by' => Auth::user()->id,
                                    'updated_by' => Auth::user()->id,
                                    'created_at' => date('Y-m-d H:i:s'),
                                    'updated_at' => date('Y-m-d H:i:s'),
                                ]);
                            }
                        }
                        
                        // NEW REMARKS
                        if(isset($w['remarks']) && count($w['remarks'])) {
                            foreach($w['remarks'] as $r) {
                                ProjectLegalBalanceCancellationRemarks::create([
                                    'project_legal_balance_cancellation_id' => $bal_cancel_obj->id,
                                    'remarks' => $r['remarks'],
                                    'remarks_date' => $r['remarks_date'],
                                    'created_by' => Auth::user()->id,
                                    'updated_by' => Auth::user()->id,
                                    'created_at' => date('Y-m-d H:i:s'),
                                    'updated_at' => date('Y-m-d H:i:s'),
                                ]);
                            }
                        }
                    }
                    elseif(!empty($w['id']) && is_numeric($w['id'])) {                // UPDATE CASE

                        $bal_cancel_obj = ProjectLegalBalanceCancellation::find($w['id']);
                        if($bal_cancel_obj != null) {
                            if(empty($w['bank_name'])) {
                                continue;
                            }
                            $array_to_update = [
                                'bank_name' => $w['bank_name'],
                                'is_completed' => empty($w['is_completed']) ? 0 : $w['is_completed'],
                                'notes' => $w['notes'],
                            ];
                            $bal_cancel_obj->fill($array_to_update);
                            if($bal_cancel_obj->isDirty()) {
                                $bal_cancel_obj->updated_by = Auth::user()->id;
                                $bal_cancel_obj->save();
                            }

                            if(isset($w['branches']) && count($w['branches'])) {
                                $branches_all_data[$w['id']] = $w['branches'];
                            }
                            
                            if(isset($w['remarks']) && count($w['remarks'])) {
                                $remarks_all_data[$w['id']] = $w['remarks'];
                            }
                        }

                        // FINALIZING BALANCE CANCELLATION IDS THAT SHOULD BE DELETED. UNSET THOSE IDS THAT ARE IN REQUEST AND ALREADY IN DB. 
                        // REMAINING IDS WOULD BE DELETED
                        if(($key = array_search((int) $w['id'], $existing_ids_bal_cancellation)) !== false) {
                            unset($existing_ids_bal_cancellation[$key]);
                        }
                    }
                }

                // UPDATE BRANCHES
                if(count($branches_all_data)) {
                    foreach($branches_all_data as $bal_cancellation_id => $section_branches) {
                        $existing_section_branches_ids = ProjectLegalBalanceCancellationBranches::where('project_legal_balance_cancellation_id', $bal_cancellation_id)
                                                        ->pluck('id')->toArray();

                        $branch_ids_to_update = [];
                        foreach($section_branches as $b) {
                            if($b['id'] == '_new') {
                                ProjectLegalBalanceCancellationBranches::create([
                                    'project_legal_balance_cancellation_id' => $bal_cancellation_id,
                                    'bank_branch' => $b['bank_branch'],
                                    'contact_person_phone' => $b['contact_person_phone'],
                                    'content' => $b['content'],
                                    'doc_submission_date' => $b['doc_submission_date'],
                                    'estimated_completion_date' => $b['estimated_completion_date'],
                                    'completion_date' => $b['completion_date'],
                                    'commission' => $b['commission'],
                                    'transfer' => $b['transfer'],
                                    'seal' => $b['seal'],
                                    'procedure_pending' => empty($b['procedure_pending']) ? 0 : $b['procedure_pending'],
                                    'created_by' => Auth::user()->id,
                                    'updated_by' => Auth::user()->id,
                                    'created_at' => date('Y-m-d H:i:s'),
                                    'updated_at' => date('Y-m-d H:i:s'),
                                ]);
                            }
                            elseif($b['id'] != '' || $b['id'] != null) {   
                                $branch_ids_to_update[] = $b['id'];

                                // FINALIZING SECTION BRANCHES IDS THAT SHOULD BE DELETED. UNSET THOSE IDS THAT ARE IN REQUEST AND ALREADY IN DB. 
                                // REMAINING IDS WOULD BE DELETED
                                if(($key = array_search((int) $b['id'], $existing_section_branches_ids)) !== false) {
                                    unset($existing_section_branches_ids[$key]);
                                }
                            }
                        }
                        // DELETING FEE BALANCE/CANCELLATION BRANCHES IDS
                        ProjectLegalBalanceCancellationBranches::whereIn('id', $existing_section_branches_ids)->delete();

                        // UPDATING REMAINING SECTION BRANCHES
                        $remaining_branches = ProjectLegalBalanceCancellationBranches::whereIn('id', $branch_ids_to_update)->get();
                        foreach($remaining_branches as $detail) {
                            $key_srchd = array_search($detail['id'], array_column($section_branches, 'id'));
                            if($key_srchd !== false) {
                                $detail->bank_branch                = $section_branches[$key_srchd]['bank_branch'];
                                $detail->contact_person_phone       = $section_branches[$key_srchd]['contact_person_phone'];
                                $detail->content                    = $section_branches[$key_srchd]['content'];
                                $detail->doc_submission_date        = $section_branches[$key_srchd]['doc_submission_date'];
                                $detail->estimated_completion_date  = $section_branches[$key_srchd]['estimated_completion_date'];
                                $detail->completion_date            = $section_branches[$key_srchd]['completion_date'];
                                $detail->commission                 = $section_branches[$key_srchd]['commission'];
                                $detail->transfer                   = $section_branches[$key_srchd]['transfer'];
                                $detail->seal                       = $section_branches[$key_srchd]['seal'];
                                $detail->procedure_pending          = empty($section_branches[$key_srchd]['procedure_pending']) ? 0 : $section_branches[$key_srchd]['procedure_pending'];
                                if($detail->isDirty()) {
                                    $detail->updated_by = Auth::user()->id;
                                    $detail->save();
                                }
                            }
                        }
                    }
                }

                // UPDATE REMARKS
                if(count($remarks_all_data)) {
                    foreach($remarks_all_data as $bal_cancellation_id => $section_remarks) {
                        $existing_section_remarks_ids = ProjectLegalBalanceCancellationRemarks::where('project_legal_balance_cancellation_id', $bal_cancellation_id)
                                                        ->pluck('id')->toArray();

                        $remarks_ids_to_update = [];
                        foreach($section_remarks as $r) {
                            if($r['id'] == '_new') {
                                ProjectLegalBalanceCancellationRemarks::create([
                                    'project_legal_balance_cancellation_id' => $bal_cancellation_id,
                                    'remarks' => $r['remarks'],
                                    'remarks_date' => $r['remarks_date'],
                                    'created_by' => Auth::user()->id,
                                    'updated_by' => Auth::user()->id,
                                    'created_at' => date('Y-m-d H:i:s'),
                                    'updated_at' => date('Y-m-d H:i:s'),
                                ]);
                            }
                            elseif($r['id'] != '' || $r['id'] != null) {   
                                $remarks_ids_to_update[] = $r['id'];

                                // FINALIZING SECTION REMARKS IDS THAT SHOULD BE DELETED. UNSET THOSE IDS THAT ARE IN REQUEST AND ALREADY IN DB. 
                                // REMAINING IDS WOULD BE DELETED
                                if(($key = array_search((int) $r['id'], $existing_section_remarks_ids)) !== false) {
                                    unset($existing_section_remarks_ids[$key]);
                                }
                            }
                        }
                        // DELETING FEE BALANCE/CANCELLATION IDS
                        ProjectLegalBalanceCancellationRemarks::whereIn('id', $existing_section_remarks_ids)->delete();

                        // UPDATING REMAINING SECTION REMARKS
                        $remaining_remarks = ProjectLegalBalanceCancellationRemarks::whereIn('id', $remarks_ids_to_update)->get();
                        foreach($remaining_remarks as $detail) {
                            $key_srchd = array_search($detail['id'], array_column($section_remarks, 'id'));
                            if($key_srchd !== false) {
                                $detail->remarks        = $section_remarks[$key_srchd]['remarks'];
                                $detail->remarks_date   = $section_remarks[$key_srchd]['remarks_date'];
                                if($detail->isDirty()) {
                                    $detail->updated_by = Auth::user()->id;
                                    $detail->save();
                                }
                            }
                        }
                    }
                }

                // DELETING BALANCE CANCELLATION IDS
                ProjectLegalBalanceCancellation::whereIn('id', $existing_ids_bal_cancellation)->delete();
                ProjectLegalBalanceCancellationRemarks::whereIn('project_legal_balance_cancellation_id', $existing_ids_bal_cancellation)->delete();
            }
            elseif(count($request->bal_cancellation) == 0 && count($existing_ids_bal_cancellation)) {
                // DELETING BALANCE CANCELLATION IDS
                ProjectLegalBalanceCancellation::whereIn('id', $existing_ids_bal_cancellation)->delete();
                ProjectLegalBalanceCancellationRemarks::whereIn('project_legal_balance_cancellation_id', $existing_ids_bal_cancellation)->delete();
            }

            // CODE FOR FEE BALANCE/CANCELLATION SECTION
            if(count($request->fee)) {
                $ids_fee_to_update = $fee_new_data = [];
                foreach($request->fee as $e) {

                    if($e['id'] == '_new') {                        // NEW CASE. AS TABISH IS ADDING "_new" KEYWORD FOR NEWLY CREATED FEE BALANCE/CANCELLATION.
                        $fee_new_data[] = [
                            'project_legal_id' => $request->project_legal_id,
                            'custody_date' => $e['custody_date'],
                            'deposit_amount' => $e['deposit_amount'],
                            'used_amount' => $e['used_amount'],
                            'unused_amount' => $e['unused_amount'],
                            'refund_date' => $e['refund_date'],
                            'fee_type' => $e['fee_type'],
                            'created_by' => Auth::user()->id,
                            'updated_by' => Auth::user()->id,
                            'created_at' => date('Y-m-d H:i:s'),
                            'updated_at' => date('Y-m-d H:i:s'),
                        ];
                    }
                    elseif($e['id'] != '' || $e['id'] != null) {                // UPDATE CASE
                        $ids_fee_to_update[] = $e['id'];

                        // FINALIZING FEE BALANCE/CANCELLATION IDS THAT SHOULD BE DELETED. UNSET THOSE IDS THAT ARE IN REQUEST AND ALREADY IN DB. 
                        // REMAINING IDS WOULD BE DELETED
                        if(($key = array_search((int) $e['id'], $existing_ids_fee)) !== false) {
                            unset($existing_ids_fee[$key]);
                        }
                    }
                }

                // DELETING FEE BALANCE/CANCELLATION IDS
                ProjectLegalFee::whereIn('id', $existing_ids_fee)->delete();

                // UPDATING REMAINING EXISTING FEE BALANCE/CANCELLATION IDS
                $fee_to_update = $request->fee;
                $remaining_fees = ProjectLegalFee::whereIn('id', $ids_fee_to_update)->get();
                
                foreach($remaining_fees as $detail) {

                    $key_srchd = array_search($detail['id'], array_column($fee_to_update, 'id'));
                    if($key_srchd !== false) {
                        $detail->custody_date   = $fee_to_update[$key_srchd]['custody_date'];
                        $detail->deposit_amount = $fee_to_update[$key_srchd]['deposit_amount'];
                        $detail->used_amount    = $fee_to_update[$key_srchd]['used_amount'];
                        $detail->unused_amount  = $fee_to_update[$key_srchd]['unused_amount'];
                        $detail->refund_date    = $fee_to_update[$key_srchd]['refund_date'];
                        $detail->fee_type       = $fee_to_update[$key_srchd]['fee_type'];
                        $detail->updated_by     = Auth::user()->id;
                        $detail->save();
                    }
                }

                // INSERTING NEW FEE BALANCE/CANCELLATION DATA
                if(count($fee_new_data)) {
                    ProjectLegalFee::insert($fee_new_data);
                }
            }
            elseif(count($request->fee) == 0 && count($existing_ids_fee)) {
                // DELETING FEE BALANCE/CANCELLATION IDS
                ProjectLegalFee::whereIn('id', $existing_ids_fee)->delete();
            }

            // CODE FOR REFUND DEPOSIT SECTION
            if(count($request->refund_deposit)) {
                $deposit_refund = [
                    'project_legal_id' => $request->project_legal_id,
                    'is_deposit' => $request->refund_deposit['is_deposit'],
                    'is_refund' => $request->refund_deposit['is_refund'],
                    'refund_date' => $request->refund_deposit['refund_date'],
                    'created_by' => Auth::user()->id,
                    'updated_by' => Auth::user()->id,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ];

                if($request->refund_deposit['id'] == '_new') {
                    $deposit_refund_data = ProjectLegalRefundStatus::create($deposit_refund);
                }
                else {
                    $deposit_refund_data = ProjectLegalRefundStatus::find($request->refund_deposit['id']);
                    if($deposit_refund_data != null) {
                        $deposit_refund_data->fill($request->refund_deposit);
                        if($deposit_refund_data->isDirty()) {
                            $deposit_refund_data->save();
                        }
                    }
                }

                // CODE FOR REFUND STATUS DETAILS SECTION
                if($deposit_refund_data->is_deposit == 'あり') {
                    // GETTING ALL EXISTING DATA FOR REFUND DETAILS
                    $existing_ids_refund_details = ProjectLegalRefundDetails::where('project_legal_refund_status_id', $deposit_refund_data->id)->pluck('id')->toArray();
    
                    if(isset($request->refund_details) && count($request->refund_details)) {
                        $refund_detail_ids_to_update = $refund_details_new_data = [];
                        foreach($request->refund_details as $rd) {
    
                            if($rd['id'] == '_new') {                        // NEW CASE. AS TABISH IS ADDING "_new" KEYWORD FOR NEWLY CREATED FEE BALANCE/CANCELLATION.
                                $refund_details_new_data[] = [
                                    'project_legal_refund_status_id' => $deposit_refund_data->id,
                                    'deposit_date' => $rd['deposit_date'],
                                    'payer' => $rd['payer'],
                                    'amount' => $rd['amount'],
                                    'refund_destination' => $rd['refund_destination'],
                                    'processing_date' => $rd['processing_date'],
                                    'is_completed' => $rd['is_completed'],
                                    'created_by' => Auth::user()->id,
                                    'updated_by' => Auth::user()->id,
                                    'created_at' => date('Y-m-d H:i:s'),
                                    'updated_at' => date('Y-m-d H:i:s'),
                                ];
                            }
                            elseif($rd['id'] != '' || $rd['id'] != null) {                // UPDATE CASE
                                $refund_detail_ids_to_update[] = $rd['id'];
    
                                // FINALIZING REFUND DETAILS IDS THAT SHOULD BE DELETED. UNSET THOSE IDS THAT ARE IN REQUEST AND ALREADY IN DB. 
                                // REMAINING IDS WOULD BE DELETED
                                if(($key = array_search((int) $rd['id'], $existing_ids_refund_details)) !== false) {
                                    unset($existing_ids_refund_details[$key]);
                                }
                            }
                        }
    
                        // DELETING REFUND DETAILS IDS
                        ProjectLegalRefundDetails::whereIn('id', $existing_ids_refund_details)->delete();
    
                        // UPDATING REMAINING EXISTING REFUND DETAILS IDS
                        $refund_details_to_update = $request->refund_details;
                        $remaining_refund_details = ProjectLegalRefundDetails::whereIn('id', $refund_detail_ids_to_update)->get();
                        
                        foreach($remaining_refund_details as $detail) {
    
                            $key_srchd = array_search($detail['id'], array_column($refund_details_to_update, 'id'));
                            if($key_srchd !== false) {
                                $detail->deposit_date       = $refund_details_to_update[$key_srchd]['deposit_date'];
                                $detail->payer              = $refund_details_to_update[$key_srchd]['payer'];
                                $detail->amount             = $refund_details_to_update[$key_srchd]['amount'];
                                $detail->refund_destination = $refund_details_to_update[$key_srchd]['refund_destination'];
                                $detail->processing_date    = $refund_details_to_update[$key_srchd]['processing_date'];
                                $detail->is_completed       = $refund_details_to_update[$key_srchd]['is_completed'];
                                $detail->updated_by         = Auth::user()->id;
                                $detail->save();
                            }
                        }
    
                        // INSERTING NEW REFUND DETAILS DATA
                        if(count($refund_details_new_data)) {
                            ProjectLegalRefundDetails::insert($refund_details_new_data);
                        }
                    }
                    elseif(isset($request->refund_details) && count($request->refund_details) == 0 && count($existing_ids_refund_details)) {
                        // DELETING REFUND DETAILS IDS
                        ProjectLegalRefundDetails::whereIn('id', $existing_ids_refund_details)->delete();
                    }
                }
            }
            
            DB::commit();
            return $this->sendResponse([], __('updated_successfully'));
        } 
        catch (QueryException $e) {
            DB::rollBack();
            $this->logError($e);
            return $this->sendError(__('failed_to_process_request'), [__('db_processing_error')], 500);
        } 
        catch (ValidationException $e) {
            return $this->sendError(__('validation_errors'), $e->validator->getMessageBag(), 422);
        }
        catch (Exception $e) {
            DB::rollBack();
            $this->logError($e);

            return $this->sendError(__('something_went_wrong'), [__('unexpected_error_occurred')], 500);
        }
    }

    // NEXT PROPOSAL NUMBER
    public function nextPorposalNumber(Request $request) 
    {
        try {
            // NEXT PROPOSAL NUMBER SUGGESTION
            $prefix = $request->proposal_number_character;

            // Validate the provided character
            $validator = Validator::make(['proposal_number_character' => $prefix], [
                'proposal_number_character' => 'required|string|size:1|alpha',
            ]);

            if ($validator->fails()) {
                // Validation failed, handle the error (e.g., return an error response)
                $errors = $validator->errors();
                // Handle the errors, e.g., return an error response
                return $this->sendError(__('something_went_wrong'), ['proposal_number_character' => [$errors->first('proposal_number_character')]], 400);
            }

            $data['next_proposal_number'] = '';
            if(trim($prefix) != '') {
                $nextProposalNumber = DB::table('project_legal')
                    ->where('proposal_number_char', $prefix)
                    ->max('proposal_number');

                // HANDLE THE CASE WHEN THERE IS NO MATCHING ROW
                $nextProposalNumber = $nextProposalNumber ? $nextProposalNumber + 1 : 1;

                // FORMAT THE NEXT PROPOSAL NUMBER
                $data['next_proposal_number'] = $prefix . $nextProposalNumber;

                return $this->sendResponse($data, __('record_found'));
            }

            return $this->sendResponse([], __('record_not_found'));
        }
        catch(Exception $e) {
            return $this->sendError(__('something_went_wrong'), [$e->getMessage()], 400);
        }
    }
}
