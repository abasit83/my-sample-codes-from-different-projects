<?php

namespace App\Http\Controllers\Api;

use Exception;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Api\BaseController as BaseController;
use App\Traits\HandlesErrorLogging;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\ValidationException;

class FullSearchController extends BaseController
{  
    use HandlesErrorLogging;  
    private $bindings = [];

    private function applySearchKeyword($search_conditions_array, $search_keyword)
    {
        $_sql = '';
        if ($search_keyword !== '') {
            $search_keyword = strtolower($search_keyword);
            $_sql = implode(" OR ", $search_conditions_array);
            
            foreach ($search_conditions_array as $condition) {
                if (strpos($condition, 'LIKE') !== false) {
                    $this->bindings[] = "%{$search_keyword}%";
                } else {
                    $this->bindings[] = $search_keyword;
                }
            }
        }
        return $_sql;
    }

    // LISTING
    public function index(Request $request)
    {
        try {
        	$limit = $request->input('limit', 50);
            $search_keyword = (isset($request->search_keyword) && trim($request->search_keyword) != '' ) ? trim($request->search_keyword) : '';
            $search_keyword = str_replace('　', ' ', $search_keyword);

            $sql = "WITH CombinedData as (";
            $sql .= "SELECT * FROM (";

            $sql .= "SELECT * FROM (
                    SELECT
                    coalesce(
                        inquiries.uniform_id,
                        psoz.uniform_id,
                        pleg.uniform_id
                    ) as uniform_id,
                    families.personal_code,
                    families.name_kana as name,
                    families.family_code,
                    coalesce(families.phone1,'') as phone1,
                    coalesce(families.phone2,'') as phone2,
                    TRIM(CONCAT_WS(' ', nullif(u1.first_name, ''), nullif(u1.last_name, ''))) as corresponding_person,
                    '' as interviewer,
                    TRIM(CONCAT_WS(' ', nullif(u3.first_name, ''), nullif(u3.last_name, ''))) as worker,
                    coalesce(psoz.dec_name_kanji,'') as dec_name_kanji,
                    coalesce(string_agg(soz_families_heirs.name,' / '),'') as heir_names_sozoku,
                    coalesce(string_agg(leg_families_heirs.name,' / '),'') as heir_names_legal,
                    coalesce(psoz.proposal_number,'') as proposal_number,
                    coalesce(inquiries.id,0) as inquiry_id,
                    0 as interview_id,
                    coalesce(psoz.id,0) as p_sozoku_id,
                    coalesce(pleg.id,0) as p_legal_id
                from
                    customers
                inner join families on
                    customers.id = families.customer_id
                inner join families as f on
                    families.family_codes_id = f.family_codes_id
                    
                INNER join inquiries on
                    customers.id = inquiries.customer_id
                    and inquiries.deleted_at is null
                INNER join interviews on
                    inquiries.id = interviews.inquiry_id
                    and interviews.deleted_at is not null
                left join project_legal as pleg on
                    interviews.id = pleg.interview_id
                    and pleg.deleted_at is null
                left join project_sozoku as psoz on
                    interviews.id = psoz.interview_id
                    and psoz.deleted_at is null
                    
                LEFT JOIN LATERAL (
                    SELECT corresponding_person_id 
                    FROM inquiries_detail
                    WHERE inquiry_id = inquiries.id
                    ORDER BY id DESC
                    LIMIT 1
                ) AS inquiry_detail ON TRUE
                left join users as u1 on
                    inquiry_detail.corresponding_person_id = u1.id
                    
                left join users as u3 on
                    psoz.worker_id = u3.id
                    
                left join project_sozoku_heir on
                    psoz.id = project_sozoku_heir.project_sozoku_id
                left join families as soz_families_heirs on
                    project_sozoku_heir.family_member_id = soz_families_heirs.id
                    
                left join project_legal_heir on
                    pleg.id = project_legal_heir.project_legal_id
                left join families as leg_families_heirs on
                    project_legal_heir.family_member_id = leg_families_heirs.id
                    
                where
                    customers.deleted_at is null
                    and (
                        inquiries.id is not null
                        or interviews.id is not null
                        or psoz.id is not null
                        or pleg.id is not null
                    ) ";
            if ($search_keyword !== '') {
                $sub_sql1 = $sub_sql2 = '';
                $search_conditions_1[] = "LOWER(families.personal_code) = ?";
                $search_conditions_1[] = "REGEXP_REPLACE(families.name::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_1[] = "REGEXP_REPLACE(families.name_kana::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_1[] = "REGEXP_REPLACE(f.name::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_1[] = "REGEXP_REPLACE(f.name_kana::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_1[] = "LOWER(families.family_code) = ?";
                $search_conditions_1[] = "LOWER(psoz.proposal_number)::text LIKE ?";
                $search_conditions_1[] = "families.phone1::text LIKE ?";
                $search_conditions_1[] = "families.phone2::text LIKE ?";
                $search_conditions_1[] = "families.phone3::text LIKE ?";
                $search_conditions_1[] = "replace(families.phone1,'-','')::text LIKE ?";
                $search_conditions_1[] = "replace(families.phone2,'-','')::text LIKE ?";
                $search_conditions_1[] = "replace(families.phone3,'-','')::text LIKE ?";
                $search_conditions_1[] = "REGEXP_REPLACE(psoz.dec_name_kana::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_1[] = "REGEXP_REPLACE(psoz.dec_name_kanji::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_1[] = "REGEXP_REPLACE(soz_families_heirs.name_kana::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_1[] = "REGEXP_REPLACE(soz_families_heirs.name::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_1[] = "REGEXP_REPLACE(leg_families_heirs.name_kana::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_1[] = "REGEXP_REPLACE(leg_families_heirs.name::text, '[[:space:]]+', ' ') LIKE ?";


                $search_conditions_1[] = "inquiries.uniform_id = ?";
                $search_conditions_1[] = "psoz.uniform_id::text LIKE ?";
                $search_conditions_1[] = "pleg.uniform_id = ?";
                $sub_sql1 = $this->applySearchKeyword($search_conditions_1, $search_keyword);
                
                $search_keyword_no_hyphens = str_replace('-', '', $search_keyword);
                $search_conditions_1[] = "families.phone1::text LIKE ?";
                $search_conditions_1[] = "families.phone2::text LIKE ?";
                $search_conditions_1[] = "families.phone3::text LIKE ?";
                $sub_sql2 = 'OR ' . $this->applySearchKeyword($search_conditions_1, $search_keyword_no_hyphens);

                $sql .= " AND (" . $sub_sql1 . " " . $sub_sql2 . ")";
            }
            $sql .= "group by 
                    families.personal_code,
                    families.name_kana,
                    families.family_code,
                    families.phone1,
                    families.phone2,
                    u1.first_name, 
                    u1.last_name,
                    u3.first_name,
                    u3.last_name,
                    inquiries.id,
                    psoz.id,
                    pleg.id
                ) AS f ";
                
            $sql .= "UNION all ";

            $sql .= "SELECT * FROM (
                    SELECT
                    coalesce(
                        inquiries.uniform_id,
                        interviews.uniform_id,
                        psoz.uniform_id,
                        pleg.uniform_id
                    ) as uniform_id,
                    families.personal_code,
                    families.name_kana as name,
                    families.family_code,
                    coalesce(families.phone1,'') as phone1,
                    coalesce(families.phone2,'') as phone2,
                    TRIM(CONCAT_WS(' ', nullif(u1.first_name, ''), nullif(u1.last_name, ''))) as corresponding_person,
                    coalesce(TRIM(CONCAT_WS(' ', nullif(u2.first_name, ''), nullif(u2.last_name, ''))),	'') as interviewer,
                    TRIM(CONCAT_WS(' ', nullif(u3.first_name, ''), nullif(u3.last_name, ''))) as worker,
                    coalesce(interviews.dec_name_kanji,psoz.dec_name_kanji,'') as dec_name_kanji,
                    coalesce(string_agg(soz_families_heirs.name,' / '),'') as heir_names_sozoku,
                    coalesce(string_agg(leg_families_heirs.name,' / '),'') as heir_names_legal,
                    coalesce(psoz.proposal_number,'') as proposal_number,
                    coalesce(inquiries.id,0) as inquiry_id,
                    coalesce(interviews.id,0) as interview_id,
                    coalesce(psoz.id,0) as p_sozoku_id,
                    coalesce(pleg.id,0) as p_legal_id
                from
                    customers
                inner join families on
                    customers.id = families.customer_id
                inner join families as f on
                    families.family_codes_id = f.family_codes_id
                    
                left join inquiries on
                    customers.id = inquiries.customer_id
                    and inquiries.deleted_at is null
                left join interviews on
                    inquiries.id = interviews.inquiry_id
                    and interviews.deleted_at is null
                left join project_legal as pleg on
                    interviews.id = pleg.interview_id
                    and pleg.deleted_at is null
                left join project_sozoku as psoz on
                    interviews.id = psoz.interview_id
                    and psoz.deleted_at is null
                    
                LEFT JOIN LATERAL (
                    SELECT corresponding_person_id 
                    FROM inquiries_detail
                    WHERE inquiry_id = inquiries.id
                    ORDER BY id DESC
                    LIMIT 1
                ) AS inquiry_detail ON TRUE
                left join users as u1 on
                    inquiry_detail.corresponding_person_id = u1.id
                    
                left join (
                    select
                        interview_id,
                        MIN(id) as min_id
                    from
                        interviews_detail
                    group by
                        interview_id
                ) as int_detail_1_min on
                    interviews.id = int_detail_1_min.interview_id
                left join interviews_detail as int_detail_1 on
                    int_detail_1_min.min_id = int_detail_1.id
                left join users as u2 on
                    int_detail_1.interviewer1 = u2.id
                    
                left join users as u3 on
                    psoz.worker_id = u3.id
                    
                left join project_sozoku_heir on
                    psoz.id = project_sozoku_heir.project_sozoku_id
                left join families as soz_families_heirs on
                    project_sozoku_heir.family_member_id = soz_families_heirs.id
                    
                left join project_legal_heir on
                    pleg.id = project_legal_heir.project_legal_id
                left join families as leg_families_heirs on
                    project_legal_heir.family_member_id = leg_families_heirs.id
                    
                where
                    customers.deleted_at is null
                    and (
                        inquiries.id is not null
                        or interviews.id is not null
                        or psoz.id is not null
                        or pleg.id is not null
                    ) ";
            if ($search_keyword !== '') {
                $sub_sql1 = $sub_sql2 = '';
                $search_conditions_2[] = "LOWER(families.personal_code) = ?";
                $search_conditions_2[] = "REGEXP_REPLACE(families.name::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_2[] = "REGEXP_REPLACE(families.name_kana::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_2[] = "REGEXP_REPLACE(f.name::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_2[] = "REGEXP_REPLACE(f.name_kana::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_2[] = "LOWER(families.family_code) = ?";
                $search_conditions_2[] = "LOWER(psoz.proposal_number)::text LIKE ?";
                $search_conditions_2[] = "families.phone1::text LIKE ?";
                $search_conditions_2[] = "families.phone2::text LIKE ?";
                $search_conditions_2[] = "families.phone3::text LIKE ?";
                $search_conditions_2[] = "replace(families.phone1,'-','')::text LIKE ?";
                $search_conditions_2[] = "replace(families.phone2,'-','')::text LIKE ?";
                $search_conditions_2[] = "replace(families.phone3,'-','')::text LIKE ?";
                $search_conditions_2[] = "interviews.dec_name_kana::text LIKE ?";
                $search_conditions_2[] = "interviews.dec_name_kanji::text LIKE ?";
                $search_conditions_2[] = "REGEXP_REPLACE(psoz.dec_name_kana::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_2[] = "REGEXP_REPLACE(psoz.dec_name_kanji::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_2[] = "REGEXP_REPLACE(soz_families_heirs.name_kana::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_2[] = "REGEXP_REPLACE(soz_families_heirs.name::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_2[] = "REGEXP_REPLACE(leg_families_heirs.name_kana::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_2[] = "REGEXP_REPLACE(leg_families_heirs.name::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_2[] = "inquiries.uniform_id = ?";
                $search_conditions_2[] = "interviews.uniform_id = ?";
                $search_conditions_2[] = "psoz.uniform_id::text LIKE ?";
                $search_conditions_2[] = "pleg.uniform_id = ?";
                $sub_sql1 = $this->applySearchKeyword($search_conditions_2, $search_keyword);
                
                $search_keyword_no_hyphens = str_replace('-', '', $search_keyword);
                $search_conditions_2[] = "families.phone1::text LIKE ?";
                $search_conditions_2[] = "families.phone2::text LIKE ?";
                $search_conditions_2[] = "families.phone3::text LIKE ?";
                $sub_sql2 = 'OR ' . $this->applySearchKeyword($search_conditions_2, $search_keyword_no_hyphens);

                $sql .= " AND (" . $sub_sql1 . " " . $sub_sql2 . ")";
            }
            $sql .= "group by 
                    families.personal_code,
                    families.name_kana,
                    families.family_code,
                    families.phone1,
                    families.phone2,
                    u1.first_name, 
                    u1.last_name,
                    u2.first_name,
                    u2.last_name,
                    u3.first_name,
                    u3.last_name,
                    inquiries.id,
                    interviews.id,
                    psoz.id,
                    pleg.id
                ) AS a ";

            $sql .= "UNION all ";
            
            $sql .= "SELECT * FROM (
                    SELECT
                    coalesce(
                        interviews.uniform_id,
                        psoz.uniform_id,
                        pleg.uniform_id
                    ) as uniform_id,
                    families.personal_code,
                    families.name_kana as name,
                    families.family_code,
                    coalesce(families.phone1,'') as phone1,
                    coalesce(families.phone2,'') as phone2,
                    '' as corresponding_person,
                    coalesce(TRIM(CONCAT_WS(' ', nullif(u2.first_name, ''), nullif(u2.last_name, ''))),	'') as interviewer,
                    TRIM(CONCAT_WS(' ', nullif(u3.first_name, ''), nullif(u3.last_name, ''))) as worker,
                    coalesce(interviews.dec_name_kanji,psoz.dec_name_kanji,'') as dec_name_kanji,
                    coalesce(string_agg(soz_families_heirs.name,' / '),'') as heir_names_sozoku,
                    coalesce(string_agg(leg_families_heirs.name,' / '),'') as heir_names_legal,
                    coalesce(psoz.proposal_number,'') as proposal_number,
                    0 as inquiry_id,
                    coalesce(interviews.id,0) as interview_id,
                    coalesce(psoz.id,0) as p_sozoku_id,
                    coalesce(pleg.id,0) as p_legal_id
                from
                    customers
                inner join families on
                    customers.id = families.customer_id
                inner join families as f on
                    families.family_codes_id = f.family_codes_id

                INNER join inquiries on
                    customers.id = inquiries.customer_id
                    and inquiries.deleted_at is not null
                INNER join interviews on
                    inquiries.id = interviews.inquiry_id
                    and interviews.deleted_at is null
                left join project_legal as pleg on
                    interviews.id = pleg.interview_id
                    and pleg.deleted_at is null
                left join project_sozoku as psoz on
                    interviews.id = psoz.interview_id
                    and psoz.deleted_at is null
                    
                left join (
                    select
                        interview_id,
                        MIN(id) as min_id
                    from
                        interviews_detail
                    group by
                        interview_id
                ) as int_detail_1_min on
                    interviews.id = int_detail_1_min.interview_id
                left join interviews_detail as int_detail_1 on
                    int_detail_1_min.min_id = int_detail_1.id
                left join users as u2 on
                    int_detail_1.interviewer1 = u2.id
                    
                left join users as u3 on
                    psoz.worker_id = u3.id
                    
                left join project_sozoku_heir on
                    psoz.id = project_sozoku_heir.project_sozoku_id
                left join families as soz_families_heirs on
                    project_sozoku_heir.family_member_id = soz_families_heirs.id
                    
                left join project_legal_heir on
                    pleg.id = project_legal_heir.project_legal_id
                left join families as leg_families_heirs on
                    project_legal_heir.family_member_id = leg_families_heirs.id
                    
                where
                    customers.deleted_at is null
                    and (
                        inquiries.id is not null
                        or interviews.id is not null
                        or psoz.id is not null
                        or pleg.id is not null
                    ) ";
            if ($search_keyword !== '') {
                $sub_sql1 = $sub_sql2 = '';
                $search_conditions_3[] = "LOWER(families.personal_code) = ?";
                $search_conditions_3[] = "REGEXP_REPLACE(families.name::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_3[] = "REGEXP_REPLACE(families.name_kana::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_3[] = "REGEXP_REPLACE(f.name::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_3[] = "REGEXP_REPLACE(f.name_kana::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_3[] = "LOWER(families.family_code) = ?";
                $search_conditions_3[] = "LOWER(psoz.proposal_number)::text LIKE ?";
                $search_conditions_3[] = "families.phone1::text LIKE ?";
                $search_conditions_3[] = "families.phone2::text LIKE ?";
                $search_conditions_3[] = "families.phone3::text LIKE ?";
                $search_conditions_3[] = "replace(families.phone1,'-','')::text LIKE ?";
                $search_conditions_3[] = "replace(families.phone2,'-','')::text LIKE ?";
                $search_conditions_3[] = "replace(families.phone3,'-','')::text LIKE ?";
                $search_conditions_3[] = "interviews.dec_name_kana::text LIKE ?";
                $search_conditions_3[] = "interviews.dec_name_kanji::text LIKE ?";
                $search_conditions_3[] = "REGEXP_REPLACE(psoz.dec_name_kana::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_3[] = "REGEXP_REPLACE(psoz.dec_name_kanji::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_3[] = "REGEXP_REPLACE(soz_families_heirs.name_kana::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_3[] = "REGEXP_REPLACE(soz_families_heirs.name::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_3[] = "REGEXP_REPLACE(leg_families_heirs.name_kana::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_3[] = "REGEXP_REPLACE(leg_families_heirs.name::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_3[] = "interviews.uniform_id = ?";
                $search_conditions_3[] = "psoz.uniform_id::text LIKE ?";
                $search_conditions_3[] = "pleg.uniform_id = ?";
                $sub_sql1 = $this->applySearchKeyword($search_conditions_3, $search_keyword);
                
                $search_keyword_no_hyphens = str_replace('-', '', $search_keyword);
                $search_conditions_3[] = "families.phone1::text LIKE ?";
                $search_conditions_3[] = "families.phone2::text LIKE ?";
                $search_conditions_3[] = "families.phone3::text LIKE ?";
                $sub_sql2 = 'OR ' . $this->applySearchKeyword($search_conditions_3, $search_keyword_no_hyphens);

                $sql .= " AND (" . $sub_sql1 . " " . $sub_sql2 . ")";
            }
            $sql .= "group by 
                    families.personal_code,
                    families.name_kana,
                    families.family_code,
                    families.phone1,
                    families.phone2,
                    u2.first_name,
                    u2.last_name,
                    u3.first_name,
                    u3.last_name,
                    interviews.id,
                    psoz.id,
                    pleg.id
                ) AS e ";
                
            $sql .= "UNION all ";
                
            $sql .= "SELECT * FROM (
                    SELECT
                    coalesce(
                        int_direct.uniform_id,
                        ps_direct_1.uniform_id,
                        pl_direct_1.uniform_id
                    ) as uniform_id,
                    families.personal_code,
                    families.name_kana as name,
                    families.family_code,
                    coalesce(families.phone1,'') as phone1,
                    coalesce(families.phone2,'') as phone2,
                    '' as corresponding_person,
                    coalesce(TRIM(CONCAT_WS(' ', nullif(u2a.first_name, ''), nullif(u2a.last_name, ''))),	'') as interviewer,
                    TRIM(CONCAT_WS(' ', nullif(u4.first_name, ''), nullif(u4.last_name, ''))) as worker,
                    coalesce(int_direct.dec_name_kanji,ps_direct_1.dec_name_kanji,'') as dec_name_kanji,
                    coalesce(string_agg(soz_families_heirs1.name,' / '),'') as heir_names_sozoku,
                    coalesce(string_agg(leg_families_heirs1.name,' / '),'') as heir_names_legal,
                    coalesce(ps_direct_1.proposal_number,'') as proposal_number,
                    0 as inquiry_id,
                    coalesce(int_direct.id,0) as interview_id,
                    coalesce(ps_direct_1.id,0) as p_sozoku_id,
                    coalesce(pl_direct_1.id,0) as p_legal_id
                from
                    customers
                left join families on
                    customers.id = families.customer_id
                inner join families as f on
                    families.family_codes_id = f.family_codes_id
                left join interviews as int_direct on
                    customers.id = int_direct.customer_id
                    and int_direct.inquiry_id is null
                    and int_direct.deleted_at is null
                left join project_legal as pl_direct_1 on
                    int_direct.id = pl_direct_1.interview_id
                    and pl_direct_1.deleted_at is null
                left join project_sozoku as ps_direct_1 on
                    int_direct.id = ps_direct_1.interview_id
                    and ps_direct_1.deleted_at is null
                    
                left join (
                    select
                        interview_id,
                        MIN(id) as min_id
                    from
                        interviews_detail
                    group by
                        interview_id
                ) as int_detail_11_min on
                    int_direct.id = int_detail_11_min.interview_id
                left join interviews_detail as int_detail_11 on
                    int_detail_11_min.min_id = int_detail_11.id
                left join users as u2a on
                    int_detail_11.interviewer1 = u2a.id
                    
                left join users as u4 on
                    ps_direct_1.worker_id = u4.id
                    
                left join project_sozoku_heir as psh1 on
                    ps_direct_1.id = psh1.project_sozoku_id
                left join families as soz_families_heirs1 on
                    psh1.family_member_id = soz_families_heirs1.id
                    
                left join project_legal_heir as plh1 on
                    pl_direct_1.id = plh1.project_legal_id
                left join families as leg_families_heirs1 on
                    plh1.family_member_id = leg_families_heirs1.id
                    
                where
                    customers.deleted_at is null
                    and (
                        int_direct.id is not null
                        or ps_direct_1.id is not null
                        or pl_direct_1.id is not null
                    ) ";
            if ($search_keyword !== '') {
                $sub_sql1 = $sub_sql2 = '';
                $search_conditions_4[] = "LOWER(families.personal_code) = ?";
                $search_conditions_4[] = "REGEXP_REPLACE(families.name::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_4[] = "REGEXP_REPLACE(families.name_kana::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_4[] = "REGEXP_REPLACE(f.name::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_4[] = "REGEXP_REPLACE(f.name_kana::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_4[] = "LOWER(families.family_code) = ?";
                $search_conditions_4[] = "LOWER(ps_direct_1.proposal_number)::text LIKE ?";
                $search_conditions_4[] = "families.phone1::text LIKE ?";
                $search_conditions_4[] = "families.phone2::text LIKE ?";
                $search_conditions_4[] = "families.phone3::text LIKE ?";
                $search_conditions_4[] = "replace(families.phone1,'-','')::text LIKE ?";
                $search_conditions_4[] = "replace(families.phone2,'-','')::text LIKE ?";
                $search_conditions_4[] = "replace(families.phone3,'-','')::text LIKE ?";
                $search_conditions_4[] = "int_direct.dec_name_kana::text LIKE ?";
                $search_conditions_4[] = "int_direct.dec_name_kanji::text LIKE ?";
                $search_conditions_4[] = "ps_direct_1.dec_name_kana::text LIKE ?";
                $search_conditions_4[] = "ps_direct_1.dec_name_kanji::text LIKE ?";
                $search_conditions_4[] = "soz_families_heirs1.name_kana::text LIKE ?";
                $search_conditions_4[] = "soz_families_heirs1.name::text LIKE ?";
                $search_conditions_4[] = "leg_families_heirs1.name_kana::text LIKE ?";
                $search_conditions_4[] = "leg_families_heirs1.name::text LIKE ?";
                $search_conditions_4[] = "int_direct.uniform_id = ?";
                $search_conditions_4[] = "ps_direct_1.uniform_id::text LIKE ?";
                $search_conditions_4[] = "pl_direct_1.uniform_id = ?";
                $sub_sql1 = $this->applySearchKeyword($search_conditions_4, $search_keyword);
                
                $search_keyword_no_hyphens = str_replace('-', '', $search_keyword);
                $search_conditions_4[] = "families.phone1::text LIKE ?";
                $search_conditions_4[] = "families.phone2::text LIKE ?";
                $search_conditions_4[] = "families.phone3::text LIKE ?";
                $sub_sql2 = 'OR ' . $this->applySearchKeyword($search_conditions_4, $search_keyword_no_hyphens);

                $sql .= " AND (" . $sub_sql1 . " " . $sub_sql2 . ")";
            }
            $sql .= "group by
                    families.personal_code,
                    families.name_kana,
                    families.family_code,
                    families.phone1,
                    families.phone2,
                    u2a.first_name,
                    u2a.last_name,
                    u4.first_name,
                    u4.last_name,
                    int_direct.id,
                    ps_direct_1.id,
                    pl_direct_1.id
                ) AS b ";
                
            $sql .= "UNION all ";
                
            $sql .= "SELECT * FROM (
                    SELECT
                    coalesce(
                        ps_direct.uniform_id,
                        pl_direct.uniform_id
                    ) as uniform_id,
                    families.personal_code,
                    families.name_kana as name,
                    families.family_code,
                    coalesce(families.phone1,'') as phone1,
                    coalesce(families.phone2,'') as phone2,
                    '' as corresponding_person,
                    '' as interviewer,
                    TRIM(CONCAT_WS(' ', nullif(u5.first_name, ''), nullif(u5.last_name, ''))) as worker,
                    coalesce(ps_direct.dec_name_kanji,'') as dec_name_kanji,
                    coalesce(string_agg(soz_families_heirs2.name,' / '),'') as heir_names_sozoku,
                    coalesce(string_agg(leg_families_heirs2.name,' / '),'') as heir_names_legal,
                    coalesce(ps_direct.proposal_number,'') as proposal_number,
                    0 as inquiry_id,
                    0 as interview_id,
                    coalesce(ps_direct.id,0) as p_sozoku_id,
                    coalesce(pl_direct.id,0) as p_legal_id
                from
                    customers
                inner join families on
                    customers.id = families.customer_id
                inner join families as f on
                    families.family_codes_id = f.family_codes_id
                    
                left join project_legal as pl_direct on
                    customers.id = pl_direct.customer_id
                    and pl_direct.interview_id is null
                    and pl_direct.deleted_at is null
                left join project_sozoku as ps_direct on
                    customers.id = ps_direct.customer_id
                    and ps_direct.interview_id is null
                    and ps_direct.deleted_at is null
                    
                left join users as u5 on
                    ps_direct.worker_id = u5.id
                    
                left join project_sozoku_heir as psh2 on
                    ps_direct.id = psh2.project_sozoku_id
                left join families as soz_families_heirs2 on
                    psh2.family_member_id = soz_families_heirs2.id
                    
                left join project_legal_heir as plh2 on
                    pl_direct.id = plh2.project_legal_id
                left join families as leg_families_heirs2 on
                    plh2.family_member_id = leg_families_heirs2.id
                    
                where
                    customers.deleted_at is null
                    and (
                        ps_direct.id is not null
                        or pl_direct.id is not null
                    ) ";
            if ($search_keyword !== '') {
                $sub_sql1 = $sub_sql2 = '';
                $search_conditions_5[] = "LOWER(families.personal_code) = ?";
                $search_conditions_5[] = "REGEXP_REPLACE(families.name::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_5[] = "REGEXP_REPLACE(families.name_kana::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_5[] = "REGEXP_REPLACE(f.name::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_5[] = "REGEXP_REPLACE(f.name_kana::text, '[[:space:]]+', ' ') LIKE ?";
                $search_conditions_5[] = "LOWER(families.family_code) = ?";
                $search_conditions_5[] = "LOWER(ps_direct.proposal_number)::text LIKE ?";
                $search_conditions_5[] = "families.phone1::text LIKE ?";
                $search_conditions_5[] = "families.phone2::text LIKE ?";
                $search_conditions_5[] = "families.phone3::text LIKE ?";
                $search_conditions_5[] = "replace(families.phone1,'-','')::text LIKE ?";
                $search_conditions_5[] = "replace(families.phone2,'-','')::text LIKE ?";
                $search_conditions_5[] = "replace(families.phone3,'-','')::text LIKE ?";
                $search_conditions_5[] = "ps_direct.dec_name_kana::text LIKE ?";
                $search_conditions_5[] = "ps_direct.dec_name_kanji::text LIKE ?";
                $search_conditions_5[] = "soz_families_heirs2.name_kana::text LIKE ?";
                $search_conditions_5[] = "soz_families_heirs2.name::text LIKE ?";
                $search_conditions_5[] = "leg_families_heirs2.name_kana::text LIKE ?";
                $search_conditions_5[] = "leg_families_heirs2.name::text LIKE ?";
                $search_conditions_5[] = "ps_direct.uniform_id::text LIKE ?";
                $search_conditions_5[] = "pl_direct.uniform_id = ?";
                $sub_sql1 = $this->applySearchKeyword($search_conditions_5, $search_keyword);
                
                $search_keyword_no_hyphens = str_replace('-', '', $search_keyword);
                $search_conditions_5[] = "families.phone1::text LIKE ?";
                $search_conditions_5[] = "families.phone2::text LIKE ?";
                $search_conditions_5[] = "families.phone3::text LIKE ?";
                $sub_sql2 = 'OR ' . $this->applySearchKeyword($search_conditions_5, $search_keyword_no_hyphens);

                $sql .= " AND (" . $sub_sql1 . " " . $sub_sql2 . ")";
            }
            $sql .= "group by 
                    families.personal_code,
                    families.name_kana,
                    families.family_code,
                    families.phone1,
                    families.phone2,
                    u5.first_name,
                    u5.last_name,
                    ps_direct.id,
                    pl_direct.id) AS c ) as d ) ";  //AS d GROUP BY d.uniform_id ORDER BY d.uniform_id::integer DESC ";
            
            $sql .= "SELECT * FROM (
                        SELECT *,
                            ROW_NUMBER() OVER (PARTITION BY uniform_id ORDER BY uniform_id) as rn
                        FROM CombinedData
                    ) AS subquery
                    WHERE rn = 1
                    ORDER BY uniform_id::integer DESC";

            /* ***************************** CSV CODE ************************************ */
            $download_csv = $request->input('download_csv', 0);
            if($download_csv == 1) {
                // Fetch all records without pagination
                $results = DB::select($sql, $this->bindings);
    
                // Convert the results to an array of uniform_id values
                $uniformIds = array_map(function($row) {
                    return (array)$row;
                }, $results);
    
                // Define the CSV headers
                $headers = ['uniform_id'];
    
                // Create a callback function to generate the CSV content
                $callback = function() use ($uniformIds, $headers) {
                    $file = fopen('php://output', 'w');
                    // Write the CSV headers
                    fputcsv($file, $headers);
                    // Write each row of data
                    foreach ($uniformIds as $row) {
                        fputcsv($file, [$row['uniform_id']]);
                    }
                    fclose($file);
                };
    
                // Return the CSV file as a download
                return Response::stream($callback, 200, [
                    'Content-Type' => 'text/csv',
                    'Content-Disposition' => 'attachment; filename="uniform_ids.csv"',
                ]);
                dd('CSV downloaded');
            }
            /* ****************************** CSV CODE - END *********************************** */
            
            $currentPage = request()->get('page', 1);
            // $limit = 50;
            $offset = ($currentPage - 1) * $limit;

            // Count the total number of records
            $countSql = "
            SELECT COUNT(*) AS total
            FROM ($sql) AS countquery
            ";
            $total = DB::select($countSql, $this->bindings)[0]->total;

            // Fetch the paginated records
            $paginatedSql = $sql . " LIMIT ? OFFSET ?";
            $paginatedBindings = array_merge($this->bindings, [$limit, $offset]);
            $results = DB::select($paginatedSql, $paginatedBindings);

            // Create the paginator
            $data = new LengthAwarePaginator($results, $total, $limit, $currentPage, [
                'path' => request()->url(),
                'query' => request()->query(),
            ]);
            

            if ($data->isNotEmpty()) {
                return $this->sendResponse($data, __('record_found'));
            }

	        return $this->sendResponse([], __('record_not_found'));
	    } 
	    catch (QueryException $e) {
            $this->logError($e);
            return $this->sendError(__('failed_to_process_request'), [$e->getMessage()], 500);
            return $this->sendError(__('failed_to_process_request'), [__('db_processing_error')], 500);
        } 
        catch (ValidationException $e) {
            return $this->sendError(__('validation_errors'), $e->validator->getMessageBag(), 422);
        }
        catch (Exception $e) {
            $this->logError($e);

            return $this->sendError(__('something_went_wrong'), [__('unexpected_error_occurred')], 500);
        }
    }
    
    // LISTING
    // public function index(Request $request)
    // {
    //     try {
    //     	$limit = $request->input('limit', 50);
    //         $search_keyword = (isset($request->search_keyword) && trim($request->search_keyword) != '' ) ? trim($request->search_keyword) : '';

    //         $sql = "SELECT * FROM (SELECT * FROM (SELECT
    //                 coalesce(
    //                     inquiries.uniform_id,
    //                     interviews.uniform_id,
    //                     psoz.uniform_id,
    //                     pleg.uniform_id
    //                 ) as uniform_id,
    //                 families.personal_code,
    //                 families.name_kana as name,
    //                 families.family_code,
    //                 coalesce(families.phone1,'') as phone1,
    //                 coalesce(families.phone2,'') as phone2,
    //                 TRIM(CONCAT_WS(' ', nullif(u1.first_name, ''), nullif(u1.last_name, ''))) as corresponding_person,
    //                 coalesce(TRIM(CONCAT_WS(' ', nullif(u2.first_name, ''), nullif(u2.last_name, ''))),	'') as interviewer,
    //                 TRIM(CONCAT_WS(' ', nullif(u3.first_name, ''), nullif(u3.last_name, ''))) as worker,
    //                 coalesce(interviews.dec_name_kanji,psoz.dec_name_kanji,'') as dec_name_kanji,
    //                 coalesce(string_agg(soz_families_heirs.name,' / '),'') as heir_names_sozoku,
    //                 coalesce(string_agg(leg_families_heirs.name,' / '),'') as heir_names_legal,
    //                 coalesce(psoz.proposal_number,'') as proposal_number,
    //                 coalesce(inquiries.id,0) as inquiry_id,
    //                 coalesce(interviews.id,0) as interview_id,
    //                 coalesce(psoz.id,0) as p_sozoku_id,
    //                 coalesce(pleg.id,0) as p_legal_id
    //             from
    //                 customers
    //             inner join families on
    //                 customers.id = families.customer_id
                    
    //             left join inquiries on
    //                 customers.id = inquiries.customer_id
    //                 and inquiries.deleted_at is null
    //             left join interviews on
    //                 inquiries.id = interviews.inquiry_id
    //                 and interviews.deleted_at is null
    //             left join project_legal as pleg on
    //                 interviews.id = pleg.interview_id
    //                 and pleg.deleted_at is null
    //             left join project_sozoku as psoz on
    //                 interviews.id = psoz.interview_id
    //                 and psoz.deleted_at is null
                    
    //             left join users as u1 on
    //                 inquiries.corresponding_person_id = u1.id
                    
    //             left join (
    //                 select
    //                     interview_id,
    //                     MIN(id) as min_id
    //                 from
    //                     interviews_detail
    //                 group by
    //                     interview_id
    //             ) as int_detail_1_min on
    //                 interviews.id = int_detail_1_min.interview_id
    //             left join interviews_detail as int_detail_1 on
    //                 int_detail_1_min.min_id = int_detail_1.id
    //             left join users as u2 on
    //                 int_detail_1.interviewer1 = u2.id
                    
    //             left join users as u3 on
    //                 psoz.worker_id = u3.id
                    
    //             left join project_sozoku_heir on
    //                 psoz.id = project_sozoku_heir.project_sozoku_id
    //             left join families as soz_families_heirs on
    //                 project_sozoku_heir.family_member_id = soz_families_heirs.id
                    
    //             left join project_legal_heir on
    //                 pleg.id = project_legal_heir.project_legal_id
    //             left join families as leg_families_heirs on
    //                 project_legal_heir.family_member_id = leg_families_heirs.id
                    
    //             where
    //                 customers.deleted_at is null
    //                 and (
    //                     inquiries.id is not null
    //                     or interviews.id is not null
    //                     or psoz.id is not null
    //                     or pleg.id is not null
    //                 ) ";
    //         if ($search_keyword !== '') {
    //         $sql .= "and (
    //                     LOWER(families.personal_code) = ?
    //                     or families.name::text like ?
    //                     or families.name_kana::text like ?
    //                     or LOWER(families.family_code) = ?
    //                     or LOWER(psoz.proposal_number)::text like ?
    //                     or families.phone1::text like ?
    //                     or families.phone2::text like ?
    //                     or families.phone3::text like ?
    //                     or replace(families.phone1,'-','')::text like ?
    //                     or replace(families.phone2,'-','')::text like ?
    //                     or replace(families.phone3,'-','')::text like ?
    //                     or interviews.dec_name_kana::text like ?
    //                     or interviews.dec_name_kanji::text like ?
    //                     or psoz.dec_name_kana::text like ?
    //                     or psoz.dec_name_kanji::text like ?
    //                     or soz_families_heirs.name_kana::text like ?
    //                     or soz_families_heirs.name::text like ?
    //                     or leg_families_heirs.name_kana::text like ?
    //                     or leg_families_heirs.name::text like ?
    //                     or inquiries.uniform_id = ?
    //                     or interviews.uniform_id = ?
    //                     or psoz.uniform_id::text like ?
    //                     or pleg.uniform_id = ?
    //                 ) ";
    //         }
    //         $sql .= "group by 
    //                 families.personal_code,
    //                 families.name_kana,
    //                 families.family_code,
    //                 families.phone1,
    //                 families.phone2,
    //                 u1.first_name, 
    //                 u1.last_name,
    //                 u2.first_name,
    //                 u2.last_name,
    //                 u3.first_name,
    //                 u3.last_name,
    //                 inquiries.id,
    //                 interviews.id,
    //                 psoz.id,
    //                 pleg.id
    //             ) AS a 
    //             UNION all
    //             SELECT * FROM (SELECT
    //                 coalesce(
    //                     inquiries.uniform_id,
    //                     psoz.uniform_id,
    //                     pleg.uniform_id
    //                 ) as uniform_id,
    //                 families.personal_code,
    //                 families.name_kana as name,
    //                 families.family_code,
    //                 coalesce(families.phone1,'') as phone1,
    //                 coalesce(families.phone2,'') as phone2,
    //                 TRIM(CONCAT_WS(' ', nullif(u1.first_name, ''), nullif(u1.last_name, ''))) as corresponding_person,
    //                 '' as interviewer,
    //                 TRIM(CONCAT_WS(' ', nullif(u3.first_name, ''), nullif(u3.last_name, ''))) as worker,
    //                 coalesce(psoz.dec_name_kanji,'') as dec_name_kanji,
    //                 coalesce(string_agg(soz_families_heirs.name,' / '),'') as heir_names_sozoku,
    //                 coalesce(string_agg(leg_families_heirs.name,' / '),'') as heir_names_legal,
    //                 coalesce(psoz.proposal_number,'') as proposal_number,
    //                 coalesce(inquiries.id,0) as inquiry_id,
    //                 0 as interview_id,
    //                 coalesce(psoz.id,0) as p_sozoku_id,
    //                 coalesce(pleg.id,0) as p_legal_id
    //             from
    //                 customers
    //             inner join families on
    //                 customers.id = families.customer_id
                    
    //             INNER join inquiries on
    //                 customers.id = inquiries.customer_id
    //                 and inquiries.deleted_at is null
    //             INNER join interviews on
    //                 inquiries.id = interviews.inquiry_id
    //                 and interviews.deleted_at is not null
    //             left join project_legal as pleg on
    //                 interviews.id = pleg.interview_id
    //                 and pleg.deleted_at is null
    //             left join project_sozoku as psoz on
    //                 interviews.id = psoz.interview_id
    //                 and psoz.deleted_at is null
                    
    //             left join users as u1 on
    //                 inquiries.corresponding_person_id = u1.id
                    
    //             left join users as u3 on
    //                 psoz.worker_id = u3.id
                    
    //             left join project_sozoku_heir on
    //                 psoz.id = project_sozoku_heir.project_sozoku_id
    //             left join families as soz_families_heirs on
    //                 project_sozoku_heir.family_member_id = soz_families_heirs.id
                    
    //             left join project_legal_heir on
    //                 pleg.id = project_legal_heir.project_legal_id
    //             left join families as leg_families_heirs on
    //                 project_legal_heir.family_member_id = leg_families_heirs.id
                    
    //             where
    //                 customers.deleted_at is null
    //                 and (
    //                     inquiries.id is not null
    //                     or interviews.id is not null
    //                     or psoz.id is not null
    //                     or pleg.id is not null
    //                 ) ";
    //         if ($search_keyword !== '') {
    //         $sql .= "and (
    //                     LOWER(families.personal_code) = ?
    //                     or families.name::text like ?
    //                     or families.name_kana::text like ?
    //                     or LOWER(families.family_code) = ?
    //                     or LOWER(psoz.proposal_number)::text like ?
    //                     or families.phone1::text like ?
    //                     or families.phone2::text like ?
    //                     or families.phone3::text like ?
    //                     or replace(families.phone1,'-','')::text like ?
    //                     or replace(families.phone2,'-','')::text like ?
    //                     or replace(families.phone3,'-','')::text like ?
    //                     or psoz.dec_name_kana::text like ?
    //                     or psoz.dec_name_kanji::text like ?
    //                     or soz_families_heirs.name_kana::text like ?
    //                     or soz_families_heirs.name::text like ?
    //                     or leg_families_heirs.name_kana::text like ?
    //                     or leg_families_heirs.name::text like ?
    //                     or inquiries.uniform_id = ?
    //                     or psoz.uniform_id::text like ?
    //                     or pleg.uniform_id = ?
    //                 ) ";
    //         }
    //         $sql .= "group by 
    //                 families.personal_code,
    //                 families.name_kana,
    //                 families.family_code,
    //                 families.phone1,
    //                 families.phone2,
    //                 u1.first_name, 
    //                 u1.last_name,
    //                 u3.first_name,
    //                 u3.last_name,
    //                 inquiries.id,
    //                 psoz.id,
    //                 pleg.id
    //             ) AS f
    //             UNION all
    //             SELECT * FROM (SELECT
    //                 coalesce(
    //                     interviews.uniform_id,
    //                     psoz.uniform_id,
    //                     pleg.uniform_id
    //                 ) as uniform_id,
    //                 families.personal_code,
    //                 families.name_kana as name,
    //                 families.family_code,
    //                 coalesce(families.phone1,'') as phone1,
    //                 coalesce(families.phone2,'') as phone2,
    //                 '' as corresponding_person,
    //                 coalesce(TRIM(CONCAT_WS(' ', nullif(u2.first_name, ''), nullif(u2.last_name, ''))),	'') as interviewer,
    //                 TRIM(CONCAT_WS(' ', nullif(u3.first_name, ''), nullif(u3.last_name, ''))) as worker,
    //                 coalesce(interviews.dec_name_kanji,psoz.dec_name_kanji,'') as dec_name_kanji,
    //                 coalesce(string_agg(soz_families_heirs.name,' / '),'') as heir_names_sozoku,
    //                 coalesce(string_agg(leg_families_heirs.name,' / '),'') as heir_names_legal,
    //                 coalesce(psoz.proposal_number,'') as proposal_number,
    //                 0 as inquiry_id,
    //                 coalesce(interviews.id,0) as interview_id,
    //                 coalesce(psoz.id,0) as p_sozoku_id,
    //                 coalesce(pleg.id,0) as p_legal_id
    //             from
    //                 customers
    //             inner join families on
    //                 customers.id = families.customer_id
                    
    //             INNER join inquiries on
    //                 customers.id = inquiries.customer_id
    //                 and inquiries.deleted_at is not null
    //             INNER join interviews on
    //                 inquiries.id = interviews.inquiry_id
    //                 and interviews.deleted_at is null
    //             left join project_legal as pleg on
    //                 interviews.id = pleg.interview_id
    //                 and pleg.deleted_at is null
    //             left join project_sozoku as psoz on
    //                 interviews.id = psoz.interview_id
    //                 and psoz.deleted_at is null
                    
    //             left join (
    //                 select
    //                     interview_id,
    //                     MIN(id) as min_id
    //                 from
    //                     interviews_detail
    //                 group by
    //                     interview_id
    //             ) as int_detail_1_min on
    //                 interviews.id = int_detail_1_min.interview_id
    //             left join interviews_detail as int_detail_1 on
    //                 int_detail_1_min.min_id = int_detail_1.id
    //             left join users as u2 on
    //                 int_detail_1.interviewer1 = u2.id
                    
    //             left join users as u3 on
    //                 psoz.worker_id = u3.id
                    
    //             left join project_sozoku_heir on
    //                 psoz.id = project_sozoku_heir.project_sozoku_id
    //             left join families as soz_families_heirs on
    //                 project_sozoku_heir.family_member_id = soz_families_heirs.id
                    
    //             left join project_legal_heir on
    //                 pleg.id = project_legal_heir.project_legal_id
    //             left join families as leg_families_heirs on
    //                 project_legal_heir.family_member_id = leg_families_heirs.id
                    
    //             where
    //                 customers.deleted_at is null
    //                 and (
    //                     inquiries.id is not null
    //                     or interviews.id is not null
    //                     or psoz.id is not null
    //                     or pleg.id is not null
    //                 ) ";
    //         if ($search_keyword !== '') {
    //         $sql .= "and (
    //                     LOWER(families.personal_code) = ?
    //                     or families.name::text like ?
    //                     or families.name_kana::text like ?
    //                     or LOWER(families.family_code) = ?
    //                     or LOWER(psoz.proposal_number)::text like ?
    //                     or families.phone1::text like ?
    //                     or families.phone2::text like ?
    //                     or families.phone3::text like ?
    //                     or replace(families.phone1,'-','')::text like ?
    //                     or replace(families.phone2,'-','')::text like ?
    //                     or replace(families.phone3,'-','')::text like ?
    //                     or interviews.dec_name_kana::text like ?
    //                     or interviews.dec_name_kanji::text like ?
    //                     or psoz.dec_name_kana::text like ?
    //                     or psoz.dec_name_kanji::text like ?
    //                     or soz_families_heirs.name_kana::text like ?
    //                     or soz_families_heirs.name::text like ?
    //                     or leg_families_heirs.name_kana::text like ?
    //                     or leg_families_heirs.name::text like ?
    //                     or interviews.uniform_id = ?
    //                     or psoz.uniform_id::text like ?
    //                     or pleg.uniform_id = ?
    //                 ) ";
    //         }
    //         $sql .= "group by 
    //                 families.personal_code,
    //                 families.name_kana,
    //                 families.family_code,
    //                 families.phone1,
    //                 families.phone2,
    //                 u2.first_name,
    //                 u2.last_name,
    //                 u3.first_name,
    //                 u3.last_name,
    //                 interviews.id,
    //                 psoz.id,
    //                 pleg.id
    //             ) AS e
    //             UNION all
    //             SELECT * FROM (SELECT
    //                 coalesce(
    //                     int_direct.uniform_id,
    //                     ps_direct_1.uniform_id,
    //                     pl_direct_1.uniform_id
    //                 ) as uniform_id,
    //                 families.personal_code,
    //                 families.name_kana as name,
    //                 families.family_code,
    //                 coalesce(families.phone1,'') as phone1,
    //                 coalesce(families.phone2,'') as phone2,
    //                 '' as corresponding_person,
    //                 coalesce(TRIM(CONCAT_WS(' ', nullif(u2a.first_name, ''), nullif(u2a.last_name, ''))),	'') as interviewer,
    //                 TRIM(CONCAT_WS(' ', nullif(u4.first_name, ''), nullif(u4.last_name, ''))) as worker,
    //                 coalesce(int_direct.dec_name_kanji,ps_direct_1.dec_name_kanji,'') as dec_name_kanji,
    //                 coalesce(string_agg(soz_families_heirs1.name,' / '),'') as heir_names_sozoku,
    //                 coalesce(string_agg(leg_families_heirs1.name,' / '),'') as heir_names_legal,
    //                 coalesce(ps_direct_1.proposal_number,'') as proposal_number,
    //                 0 as inquiry_id,
    //                 coalesce(int_direct.id,0) as interview_id,
    //                 coalesce(ps_direct_1.id,0) as p_sozoku_id,
    //                 coalesce(pl_direct_1.id,0) as p_legal_id
    //             from
    //                 customers
    //             left join families on
    //                 customers.id = families.customer_id
    //             left join interviews as int_direct on
    //                 customers.id = int_direct.customer_id
    //                 and int_direct.inquiry_id is null
    //                 and int_direct.deleted_at is null
    //             left join project_legal as pl_direct_1 on
    //                 int_direct.id = pl_direct_1.interview_id
    //                 and pl_direct_1.deleted_at is null
    //             left join project_sozoku as ps_direct_1 on
    //                 int_direct.id = ps_direct_1.interview_id
    //                 and ps_direct_1.deleted_at is null
                    
    //             left join (
    //                 select
    //                     interview_id,
    //                     MIN(id) as min_id
    //                 from
    //                     interviews_detail
    //                 group by
    //                     interview_id
    //             ) as int_detail_11_min on
    //                 int_direct.id = int_detail_11_min.interview_id
    //             left join interviews_detail as int_detail_11 on
    //                 int_detail_11_min.min_id = int_detail_11.id
    //             left join users as u2a on
    //                 int_detail_11.interviewer1 = u2a.id
                    
    //             left join users as u4 on
    //                 ps_direct_1.worker_id = u4.id
                    
    //             left join project_sozoku_heir as psh1 on
    //                 ps_direct_1.id = psh1.project_sozoku_id
    //             left join families as soz_families_heirs1 on
    //                 psh1.family_member_id = soz_families_heirs1.id
                    
    //             left join project_legal_heir as plh1 on
    //                 pl_direct_1.id = plh1.project_legal_id
    //             left join families as leg_families_heirs1 on
    //                 plh1.family_member_id = leg_families_heirs1.id
                    
    //             where
    //                 customers.deleted_at is null
    //                 and (
    //                     int_direct.id is not null
    //                     or ps_direct_1.id is not null
    //                     or pl_direct_1.id is not null
    //                 ) ";
    //         if ($search_keyword !== '') {
    //         $sql .= "and (
    //                     LOWER(families.personal_code) = ?
    //                     or families.name::text like ?
    //                     or families.name_kana::text like ?
    //                     or LOWER(families.family_code) = ?
    //                     or LOWER(ps_direct_1.proposal_number)::text like ?
    //                     or families.phone1::text like ?
    //                     or families.phone2::text like ?
    //                     or families.phone3::text like ?
    //                     or replace(families.phone1,'-','')::text like ?
    //                     or replace(families.phone2,'-','')::text like ?
    //                     or replace(families.phone3,'-','')::text like ?
    //                     or int_direct.dec_name_kana::text like ?
    //                     or int_direct.dec_name_kanji::text like ?
    //                     or ps_direct_1.dec_name_kana::text like ?
    //                     or ps_direct_1.dec_name_kanji::text like ?
    //                     or soz_families_heirs1.name_kana::text like ?
    //                     or soz_families_heirs1.name::text like ?
    //                     or leg_families_heirs1.name_kana::text like ?
    //                     or leg_families_heirs1.name::text like ?
    //                     or int_direct.uniform_id = ?
    //                     or ps_direct_1.uniform_id::text like ?
    //                     or pl_direct_1.uniform_id = ?
    //                 ) ";
    //         }
    //         $sql .= "group by
    //                 families.personal_code,
    //                 families.name_kana,
    //                 families.family_code,
    //                 families.phone1,
    //                 families.phone2,
    //                 u2a.first_name,
    //                 u2a.last_name,
    //                 u4.first_name,
    //                 u4.last_name,
    //                 int_direct.id,
    //                 ps_direct_1.id,
    //                 pl_direct_1.id
    //             ) AS b
    //             UNION all
    //             SELECT * FROM (SELECT
    //                 coalesce(
    //                     ps_direct.uniform_id,
    //                     pl_direct.uniform_id
    //                 ) as uniform_id,
    //                 families.personal_code,
    //                 families.name_kana as name,
    //                 families.family_code,
    //                 coalesce(families.phone1,'') as phone1,
    //                 coalesce(families.phone2,'') as phone2,
    //                 '' as corresponding_person,
    //                 '' as interviewer,
    //                 TRIM(CONCAT_WS(' ', nullif(u5.first_name, ''), nullif(u5.last_name, ''))) as worker,
    //                 coalesce(ps_direct.dec_name_kanji,'') as dec_name_kanji,
    //                 coalesce(string_agg(soz_families_heirs2.name,' / '),'') as heir_names_sozoku,
    //                 coalesce(string_agg(leg_families_heirs2.name,' / '),'') as heir_names_legal,
    //                 coalesce(ps_direct.proposal_number,'') as proposal_number,
    //                 0 as inquiry_id,
    //                 0 as interview_id,
    //                 coalesce(ps_direct.id,0) as p_sozoku_id,
    //                 coalesce(pl_direct.id,0) as p_legal_id
    //             from
    //                 customers
    //             inner join families on
    //                 customers.id = families.customer_id
                    
    //             left join project_legal as pl_direct on
    //                 customers.id = pl_direct.customer_id
    //                 and pl_direct.interview_id is null
    //                 and pl_direct.deleted_at is null
    //             left join project_sozoku as ps_direct on
    //                 customers.id = ps_direct.customer_id
    //                 and ps_direct.interview_id is null
    //                 and ps_direct.deleted_at is null
                    
    //             left join users as u5 on
    //                 ps_direct.worker_id = u5.id
                    
    //             left join project_sozoku_heir as psh2 on
    //                 ps_direct.id = psh2.project_sozoku_id
    //             left join families as soz_families_heirs2 on
    //                 psh2.family_member_id = soz_families_heirs2.id
                    
    //             left join project_legal_heir as plh2 on
    //                 pl_direct.id = plh2.project_legal_id
    //             left join families as leg_families_heirs2 on
    //                 plh2.family_member_id = leg_families_heirs2.id
                    
    //             where
    //                 customers.deleted_at is null
    //                 and (
    //                     ps_direct.id is not null
    //                     or pl_direct.id is not null
    //                 ) ";
    //         if ($search_keyword !== '') {
    //         $sql .= "and (
    //                     LOWER(families.personal_code) = ?
    //                     or families.name::text like ?
    //                     or families.name_kana::text like ?
    //                     or LOWER(families.family_code) = ?
    //                     or LOWER(ps_direct.proposal_number)::text like ?
    //                     or families.phone1::text like ?
    //                     or families.phone2::text like ?
    //                     or families.phone3::text like ?
    //                     or replace(families.phone1,'-','')::text like ?
    //                     or replace(families.phone2,'-','')::text like ?
    //                     or replace(families.phone3,'-','')::text like ?
    //                     or ps_direct.dec_name_kana::text like ?
    //                     or ps_direct.dec_name_kanji::text like ?
    //                     or soz_families_heirs2.name_kana::text like ?
    //                     or soz_families_heirs2.name::text like ?
    //                     or leg_families_heirs2.name_kana::text like ?
    //                     or leg_families_heirs2.name::text like ?
    //                     or ps_direct.uniform_id::text like ?
    //                     or pl_direct.uniform_id = ?
    //                 ) ";
    //         }
    //         $sql .= "group by 
    //                 families.personal_code,
    //                 families.name_kana,
    //                 families.family_code,
    //                 families.phone1,
    //                 families.phone2,
    //                 u5.first_name,
    //                 u5.last_name,
    //                 ps_direct.id,
    //                 pl_direct.id) AS c ) AS d ORDER BY d.uniform_id::integer DESC ";
            
    //         $bindings = [];
    //         if ($search_keyword !== '') {
    //             $search_keyword = strtolower($search_keyword);
    //             $bindings = [
    //                 $search_keyword,
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 $search_keyword,
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 $search_keyword,
    //                 $search_keyword,
    //                 "%$search_keyword%",
    //                 $search_keyword,
    //                 $search_keyword,
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 $search_keyword,
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 $search_keyword,
    //                 "%$search_keyword%",
    //                 $search_keyword,
    //                 $search_keyword,
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 $search_keyword,
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 $search_keyword,
    //                 "%$search_keyword%",
    //                 $search_keyword,
    //                 $search_keyword,
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 $search_keyword,
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 $search_keyword,
    //                 "%$search_keyword%",
    //                 $search_keyword,
    //                 $search_keyword,
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 $search_keyword,
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 "%$search_keyword%",
    //                 $search_keyword,
    //             ];
    //         }

    //         /* ***************************** CSV CODE ************************************ */
    //         $download_csv = $request->input('download_csv', 0);
    //         if($download_csv == 1) {
    //             // Fetch all records without pagination
    //             $results = DB::select($sql, $bindings);
    
    //             // Convert the results to an array of uniform_id values
    //             $uniformIds = array_map(function($row) {
    //                 return (array)$row;
    //             }, $results);
    
    //             // Define the CSV headers
    //             $headers = ['uniform_id'];
    
    //             // Create a callback function to generate the CSV content
    //             $callback = function() use ($uniformIds, $headers) {
    //                 $file = fopen('php://output', 'w');
    //                 // Write the CSV headers
    //                 fputcsv($file, $headers);
    //                 // Write each row of data
    //                 foreach ($uniformIds as $row) {
    //                     fputcsv($file, [$row['uniform_id']]);
    //                 }
    //                 fclose($file);
    //             };
    
    //             // Return the CSV file as a download
    //             return Response::stream($callback, 200, [
    //                 'Content-Type' => 'text/csv',
    //                 'Content-Disposition' => 'attachment; filename="uniform_ids.csv"',
    //             ]);
    //             dd('CSV downloaded');
    //         }
    //         /* ****************************** CSV CODE - END *********************************** */
            
    //         $currentPage = request()->get('page', 1);
    //         // $limit = 50;
    //         $offset = ($currentPage - 1) * $limit;

    //         // Count the total number of records
    //         $countSql = "
    //         SELECT COUNT(*) AS total
    //         FROM ($sql) AS subquery
    //         ";
    //         $total = DB::select($countSql, $bindings)[0]->total;

    //         // Fetch the paginated records
    //         $paginatedSql = $sql . " LIMIT ? OFFSET ?";
    //         $paginatedBindings = array_merge($bindings, [$limit, $offset]);
    //         $results = DB::select($paginatedSql, $paginatedBindings);

    //         // Create the paginator
    //         $data = new LengthAwarePaginator($results, $total, $limit, $currentPage, [
    //             'path' => request()->url(),
    //             'query' => request()->query(),
    //         ]);
            

    //         if ($data->isNotEmpty()) {
    //             return $this->sendResponse($data, __('record_found'));
    //         }

	//         return $this->sendResponse([], __('record_not_found'));
	//     } 
	//     catch (QueryException $e) {
    //         $this->logError($e);
    //         return $this->sendError(__('failed_to_process_request'), [$e->getMessage()], 500);
    //         return $this->sendError(__('failed_to_process_request'), [__('db_processing_error')], 500);
    //     } 
    //     catch (ValidationException $e) {
    //         return $this->sendError(__('validation_errors'), $e->validator->getMessageBag(), 422);
    //     }
    //     catch (Exception $e) {
    //         $this->logError($e);

    //         return $this->sendError(__('something_went_wrong'), [__('unexpected_error_occurred')], 500);
    //     }
    // }

    // LISTING
    // public function index(Request $request)
    // {
    //     try {
    //     	$limit 		 = $request->input('limit', 50);
    //         $sort_type 	 = 'desc';
    //         $sort_column = 'customers.id';

    //         $search_keyword = (isset($request->search_keyword) && trim($request->search_keyword) != '' ) ? trim($request->search_keyword) : '';

    //         $query = Customer::select(
    //             DB::raw("
    //                 COALESCE(inquiries.uniform_id,interviews.uniform_id,int_direct.uniform_id,psoz.uniform_id,ps_direct.uniform_id,ps_direct_1.uniform_id,pleg.uniform_id,pl_direct.uniform_id,pl_direct_1.uniform_id) AS uniform_id,
    //                 customers.personal_code,
    //                 families.name_kana as name,
    //                 families.family_code,
    //                 COALESCE(families.phone1, '') AS phone1,
    //                 COALESCE(families.phone2, '') AS phone2,
    //                 COALESCE(psoz.proposal_number, ps_direct.proposal_number, ps_direct_1.proposal_number, '') AS proposal_number,
    //                 COALESCE(interviews.dec_name_kanji, int_direct.dec_name_kanji, psoz.dec_name_kanji, ps_direct_1.dec_name_kanji, ps_direct.dec_name_kanji, '') AS dec_name_kanji,
    //                 TRIM(CONCAT_WS(' ', NULLIF(u1.first_name, ''), NULLIF(u1.last_name, ''))) AS corresponding_person,
    //                 COALESCE(TRIM(CONCAT_WS(' ', NULLIF(u2.first_name, ''), NULLIF(u2.last_name, ''))), TRIM(CONCAT_WS(' ', NULLIF(u2a.first_name, ''), NULLIF(u2a.last_name, '')))) AS interviewer,
    //                 TRIM(CONCAT_WS(' ', NULLIF(u3.first_name, ''), NULLIF(u3.last_name, ''))) AS worker,
    //                 COALESCE(string_agg(soz_families_heirs.name, ' / '), string_agg(soz_families_heirs1.name, ' / '), string_agg(soz_families_heirs2.name, ' / '), '') AS heir_names_sozoku,
    //                 COALESCE(string_agg(leg_families_heirs.name, ' / '), string_agg(leg_families_heirs1.name, ' / '), string_agg(leg_families_heirs2.name, ' / '), '') AS heir_names_legal,
    //                 COALESCE(psoz.id, ps_direct.id, ps_direct_1.id, 0) AS p_sozoku_id,
    //                 COALESCE(pleg.id, pl_direct.id, pl_direct_1.id, 0) AS p_legal_id,
    //                 COALESCE(interviews.id, int_direct.id, 0) AS interview_id,
    //                 COALESCE(inquiries.id, 0) AS inquiry_id
    //             ")
    //         )
    //         ->join('families', 'customers.id', '=', 'families.customer_id');
            
    //         $query->leftJoin('inquiries', function($join) {
    //             $join->on('customers.id', '=', 'inquiries.customer_id');
    //             $join->whereNull('inquiries.deleted_at');
    //         })
    //         ->leftJoin('interviews', function($join) {
    //             $join->on('inquiries.id', '=', 'interviews.inquiry_id');
    //             $join->whereNull('interviews.deleted_at');
    //         })
    //         ->leftJoin('project_legal as pleg', function($join) {
    //             $join->on('interviews.id', '=', 'pleg.interview_id')
    //             ->whereNull('pleg.deleted_at');
    //         })
    //         ->leftJoin('project_sozoku as psoz', function($join) {
    //             $join->on('interviews.id', '=', 'psoz.interview_id')
    //             ->whereNull('psoz.deleted_at');
    //         });

    //         $query->leftJoin('interviews as int_direct', function ($join) {
    //             $join->on('customers.id', '=', 'int_direct.customer_id')
    //             ->whereNull('int_direct.inquiry_id')
    //             ->whereNull('int_direct.deleted_at');
    //         })
    //         ->leftJoin('project_legal as pl_direct_1', function ($join) {
    //             $join->on('int_direct.id', '=', 'pl_direct_1.interview_id')
    //             ->whereNull('pl_direct_1.deleted_at');
    //         })
    //         ->leftJoin('project_sozoku as ps_direct_1', function ($join) {
    //             $join->on('int_direct.id', '=', 'ps_direct_1.interview_id')
    //             ->whereNull('ps_direct_1.deleted_at');
    //         });

    //         $query->leftJoin('project_legal as pl_direct', function ($join) {
    //             $join->on('customers.id', '=', 'pl_direct.customer_id')
    //             ->whereNull('pl_direct.interview_id')
    //             ->whereNull('pl_direct.deleted_at');
    //         })
    //         ->leftJoin('project_sozoku as ps_direct', function ($join) {
    //             $join->on('customers.id', '=', 'ps_direct.customer_id')
    //             ->whereNull('ps_direct.interview_id')
    //             ->whereNull('ps_direct.deleted_at');
    //         });
            
    //         $query->leftJoin('users as u1', 'inquiries.corresponding_person_id', '=', 'u1.id');
            
    //         // >>>>>>>>>>
    //         $query->leftJoin(DB::raw('(SELECT interview_id, MIN(id) AS min_id FROM interviews_detail GROUP BY interview_id) AS int_detail_1_min'), function($join) {
    //             $join->on('interviews.id', '=', 'int_detail_1_min.interview_id');
    //         })
    //         ->leftJoin('interviews_detail as int_detail_1', 'int_detail_1_min.min_id', '=', 'int_detail_1.id')
    //         ->leftJoin('users as u2', 'int_detail_1.interviewer1', '=', 'u2.id');
            
    //         // >>>>>>>>>>
    //         $query->leftJoin(DB::raw('(SELECT interview_id, MIN(id) AS min_id FROM interviews_detail GROUP BY interview_id) AS int_detail_11_min'), function($join) {
    //             $join->on('int_direct.id', '=', 'int_detail_11_min.interview_id');
    //         })
    //         ->leftJoin('interviews_detail as int_detail_11', 'int_detail_11_min.min_id', '=', 'int_detail_11.id')
    //         ->leftJoin('users as u2a', 'int_detail_11.interviewer1', '=', 'u2a.id');
            
    //         $query->leftJoin('users as u3', 'psoz.worker_id', '=', 'u3.id');

    //         $query->leftJoin('project_sozoku_heir', 'psoz.id', '=', 'project_sozoku_heir.project_sozoku_id')
    //         ->leftJoin('families as soz_families_heirs', 'project_sozoku_heir.family_member_id', '=', 'soz_families_heirs.id');

    //         $query->leftJoin('project_sozoku_heir as psh1', 'ps_direct.id', '=', 'psh1.project_sozoku_id')
    //         ->leftJoin('families as soz_families_heirs1', 'psh1.family_member_id', '=', 'soz_families_heirs1.id');
            
    //         $query->leftJoin('project_sozoku_heir as psh2', 'ps_direct_1.id', '=', 'psh2.project_sozoku_id')
    //         ->leftJoin('families as soz_families_heirs2', 'psh2.family_member_id', '=', 'soz_families_heirs2.id');
            
    //         $query->leftJoin('project_legal_heir', 'pleg.id', '=', 'project_legal_heir.project_legal_id')
    //         ->leftJoin('families as leg_families_heirs', 'project_legal_heir.family_member_id', '=', 'leg_families_heirs.id');
            
    //         $query->leftJoin('project_legal_heir as plh1', 'pl_direct.id', '=', 'plh1.project_legal_id')
    //         ->leftJoin('families as leg_families_heirs1', 'plh1.family_member_id', '=', 'leg_families_heirs1.id');
            
    //         $query->leftJoin('project_legal_heir as plh2', 'pl_direct_1.id', '=', 'plh2.project_legal_id')
    //         ->leftJoin('families as leg_families_heirs2', 'plh2.family_member_id', '=', 'leg_families_heirs2.id');
            
    //         $query->whereNull('customers.deleted_at');

    //         $query->where(function($query) {
    //             $query->whereNotNull('inquiries.id')
    //                   ->orWhereNotNull('interviews.id')
    //                   ->orWhereNotNull('int_direct.id')
    //                   ->orWhereNotNull('psoz.id')
    //                   ->orWhereNotNull('pleg.id')
    //                   ->orWhereNotNull('ps_direct.id')
    //                   ->orWhereNotNull('pl_direct.id')
    //                   ->orWhereNotNull('ps_direct_1.id')
    //                   ->orWhereNotNull('pl_direct_1.id');
    //         });
    //         // $query->whereIn('customers.personal_code', $personalCodes);
            
    //         if ($search_keyword !== '') {
    //             $query->where(function ($q) use ($search_keyword) {
    //                 $search_keyword = strtolower($search_keyword);
    //                 $q->where(DB::raw('LOWER(customers.personal_code)'), $search_keyword)
    //                     ->orWhere('customers.cust_name', 'LIKE', "%$search_keyword%")
    //                     ->orWhere('customers.cust_name_kana', 'LIKE', "%$search_keyword%")
    //                     ->orWhere(DB::raw('LOWER(families.family_code)'), $search_keyword)
    //                     ->orWhere(DB::raw('LOWER(psoz.proposal_number)'), 'LIKE', "%$search_keyword%")
    //                     ->orWhere(DB::raw('LOWER(ps_direct.proposal_number)'), 'LIKE', "%$search_keyword%")
    //                     ->orWhere(DB::raw('LOWER(ps_direct_1.proposal_number)'), 'LIKE', "%$search_keyword%")
    //                     ->orWhere('families.phone1', 'LIKE', "%$search_keyword%")
    //                     ->orWhere('families.phone2', 'LIKE', "%$search_keyword%")
    //                     ->orWhere('families.phone3', 'LIKE', "%$search_keyword%")
    //                     ->orWhere(DB::raw('REPLACE(families.phone1, \'-\', \'\')'), 'LIKE', "%$search_keyword%")
    //                     ->orWhere(DB::raw('REPLACE(families.phone2, \'-\', \'\')'), 'LIKE', "%$search_keyword%")
    //                     ->orWhere(DB::raw('REPLACE(families.phone3, \'-\', \'\')'), 'LIKE', "%$search_keyword%")
    //                     ->orWhere('interviews.dec_name_kana', 'LIKE', "%$search_keyword%")
    //                     ->orWhere('interviews.dec_name_kanji', 'LIKE', "%$search_keyword%")
    //                     ->orWhere('int_direct.dec_name_kana', 'LIKE', "%$search_keyword%")
    //                     ->orWhere('int_direct.dec_name_kanji', 'LIKE', "%$search_keyword%")
    //                     ->orWhere('psoz.dec_name_kana', 'LIKE', "%$search_keyword%")
    //                     ->orWhere('psoz.dec_name_kanji', 'LIKE', "%$search_keyword%")
    //                     ->orWhere('ps_direct.dec_name_kana', 'LIKE', "%$search_keyword%")
    //                     ->orWhere('ps_direct.dec_name_kanji', 'LIKE', "%$search_keyword%")
    //                     ->orWhere('ps_direct_1.dec_name_kana', 'LIKE', "%$search_keyword%")
    //                     ->orWhere('ps_direct_1.dec_name_kanji', 'LIKE', "%$search_keyword%")
    //                     ->orWhere('soz_families_heirs.name_kana', 'LIKE', "%$search_keyword%")
    //                     ->orWhere('soz_families_heirs.name', 'LIKE', "%$search_keyword%")
    //                     ->orWhere('soz_families_heirs1.name_kana', 'LIKE', "%$search_keyword%")
    //                     ->orWhere('soz_families_heirs1.name', 'LIKE', "%$search_keyword%")
    //                     ->orWhere('soz_families_heirs2.name_kana', 'LIKE', "%$search_keyword%")
    //                     ->orWhere('soz_families_heirs2.name', 'LIKE', "%$search_keyword%")
    //                     ->orWhere('leg_families_heirs.name_kana', 'LIKE', "%$search_keyword%")
    //                     ->orWhere('leg_families_heirs.name', 'LIKE', "%$search_keyword%")
    //                     ->orWhere('leg_families_heirs1.name_kana', 'LIKE', "%$search_keyword%")
    //                     ->orWhere('leg_families_heirs1.name', 'LIKE', "%$search_keyword%")
    //                     ->orWhere('leg_families_heirs2.name_kana', 'LIKE', "%$search_keyword%")
    //                     ->orWhere('leg_families_heirs2.name', 'LIKE', "%$search_keyword%");
            
    //                 $q->orWhere('inquiries.uniform_id', $search_keyword);
    //                 $q->orWhere('interviews.uniform_id', $search_keyword);
    //                 $q->orWhere('int_direct.uniform_id', $search_keyword);
    //                 $q->orWhere('psoz.uniform_id', 'LIKE', "%$search_keyword%");
    //                 $q->orWhere('ps_direct.uniform_id', 'LIKE', "%$search_keyword%");
    //                 $q->orWhere('ps_direct_1.uniform_id', 'LIKE', "%$search_keyword%");
    //                 $q->orWhere('pleg.uniform_id', $search_keyword);
    //                 $q->orWhere('pl_direct.uniform_id', $search_keyword);
    //                 $q->orWhere('pl_direct_1.uniform_id', $search_keyword);
    //                 // if (is_numeric($search_keyword) && $search_keyword <= 2147483647) {     // strlen, to avoid "Numeric value out of range" error
    //                 // }
    //             });
    //         }
            
    //         $query->groupBy('customers.id', 'families.name_kana', 'families.family_code', 'families.phone1', 'families.phone2','u1.first_name','u1.last_name','u2.first_name','u2.last_name', 'u2a.first_name','u2a.last_name','u3.first_name','u3.last_name','inquiries.id', 'interviews.id', 'int_direct.id','psoz.id', 'pleg.id', 'ps_direct.id', 'pl_direct.id', 'ps_direct_1.id', 'pl_direct_1.id');

    //         $query->orderBy($sort_column, $sort_type);
    //         // echo $query->toSql();exit;
    //         $data = $query->paginate($limit);

    //         if ($data->isNotEmpty()) {
    //             return $this->sendResponse($data, __('record_found'));
    //         }

	//         return $this->sendResponse([], __('record_not_found'));
	//     } 
	//     catch (QueryException $e) {
    //         $this->logError($e);
    //         return $this->sendError(__('failed_to_process_request'), [__('db_processing_error')], 500);
    //     } 
    //     catch (ValidationException $e) {
    //         return $this->sendError(__('validation_errors'), $e->validator->getMessageBag(), 422);
    //     }
    //     catch (Exception $e) {
    //         $this->logError($e);

    //         return $this->sendError(__('something_went_wrong'), [__('unexpected_error_occurred')], 500);
    //     }
    // }
}
