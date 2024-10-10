<?php

namespace App\Http\Controllers\Api;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Api\BaseController as BaseController;
use App\Models\Office;
use App\Utilities\InterviewResultType;
use App\Utilities\ProjectApplicationTypes;
use Departments;
use InsuranceResultType;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use App\Traits\HandlesErrorLogging;
class DashboardSozokuController extends BaseController
{
    use HandlesErrorLogging;
    private $department_id = Departments::TAX;
    private $department_insurance_id = Departments::INSURANCE;
    private $admin_id;
    private $js_admin_ids_array;
    private $js_admin_ids_raw;

    public function __construct()
    {
        $this->admin_id = config('app.3ace_admin_id');
        $this->js_admin_ids_array = [config('app.3ace_admin_id'), config('app.legal_admin_id'), config('app.insurance_admin_id')];
        $this->js_admin_ids_raw = "(".config('app.3ace_admin_id').", ".config('app.legal_admin_id').", ".config('app.insurance_admin_id').")";
    }

    private $months = [
        '01' => 'January',
        '02' => 'February',
        '03' => 'March',
        '04' => 'April',
        '05' => 'May',
        '06' => 'June',
        '07' => 'July',
        '08' => 'August',
        '09' => 'September',
        '10' => 'October',
        '11' => 'November',
        '12' => 'December'
    ];

    // TAB 1
    public function tab1(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'start_date' => 'required|date_format:Y-m',
                'end_date' => 'required|date_format:Y-m',
                'is_yearly_total' => 'in:1,0',
                'is_grand_total' => 'in:1,0',
                'search_type' => 'required|in:area,office,employee',
            ]);
        
            if ($validator->fails()) {
                $errors = $validator->errors();
                $error_array = [];
                if($errors->first('start_date')) {
                    $error_array['start_date'] = [$errors->first('start_date')];
                }
                if($errors->first('end_date')) {
                    $error_array['end_date'] = [$errors->first('end_date')];
                }
                if($errors->first('is_yearly_total')) {
                    $error_array['is_yearly_total'] = [$errors->first('is_yearly_total')];
                }
                if($errors->first('is_grand_total')) {
                    $error_array['is_grand_total'] = [$errors->first('is_grand_total')];
                }
                if($errors->first('search_type')) {
                    $error_array['search_type'] = [$errors->first('search_type')];
                }
                return $this->sendError(__('something_went_wrong'), $error_array, 422);
            }

            $search_type = $request->input('search_type', '');      // Possible values area, office, employee
            $is_yearly_total = $request->input('is_yearly_total', 0);
            $is_grand_total = $request->input('is_grand_total', 0);

            // Assuming $start_date and $end_date are in 'Y-m' format, e.g., '2023-12'
            $start_date = Carbon::createFromFormat('Y-m-d', $request->start_date.'-01');
            $end_date = Carbon::createFromFormat('Y-m-d', $request->end_date.'-01')->endOfMonth(); // Ensure the end date covers the end of the month

            $currentDate = $start_date->copy();
            $previousYear = $start_date->year;

            // RESPONSE DATA CODE
            $response_data = [];            
            // Initial run for Function
            $response_data[$currentDate->year][$currentDate->format('m')] = $this->tab1Data($request, $currentDate, 'month');
            // Move to the next month for the loop start
            $currentDate->addMonth();
            
            while ($currentDate->lessThanOrEqualTo($end_date)) {
                // Check if the year has changed, indicating the start of a new year
                if ($currentDate->year != $previousYear && $is_yearly_total == 1) {
                    // Run Function for total of year calculation before processing the new year
                    $response_data[$previousYear]['year_total'] = $this->tab1Data($request, $previousYear, 'year');
                    $previousYear = $currentDate->year;
                }

                // Run Function for the current month
                $response_data[$currentDate->year][$currentDate->format('m')] = $this->tab1Data($request, $currentDate, 'month');

                // Move to the next month
                $currentDate->addMonth();
            }

            // After completing the loop, check if Function needs to be called for the end date's year
            if ($end_date->year == $previousYear && $is_yearly_total == 1) {
                $response_data[$end_date->year]['year_total'] = $this->tab1Data($request, $end_date->year, 'year');
            }
            
            // For grand total row wise
            if($is_grand_total == 1) {
                $response_data['all_years']['grand_total'] = $this->tab1Data($request, $end_date->year, 'all_years');
            }
            // dd($response_data);

            // ALL DATA
            $mc_all_data = $others_all_data = $cities_all_data = $offices_all_data = $total_column_data = [];
            foreach($response_data as $year => $record) {
                
                if(is_array($record)) {
                    foreach($record as $month => $mc_records) {
                        if(is_array($mc_records) && count($mc_records)) {
                            foreach($mc_records as $mc_rec) {
                                $worker_check = $others_check = 0;

                                // ALL MCs DATA
                                if($mc_rec->is_sales == 1 && $mc_rec->status == 1 && $search_type == 'employee') {
                                    if(!isset($mc_all_data[$mc_rec->id.'-'.$mc_rec->office_id]) && !empty($mc_rec->office_id) && $month != 'year_total' && $month != 'grand_total') {
                                        $worker_check = 1;
                                        $mc_all_data[$mc_rec->id.'-'.$mc_rec->office_id] = [
                                            'Base' => $mc_rec->office_name,
                                            'MC' => $mc_rec->mc_name,
                                            $this->months[$month] . ' ' . $year => $this->tab1Array($mc_rec)
                                        ];
                                    }
                                    elseif(isset($mc_all_data[$mc_rec->id.'-'.$mc_rec->office_id]) && !empty($mc_rec->office_id) && $month != 'year_total' && $month != 'grand_total') {
                                        $worker_check = 1;
                                        $mc_all_data[$mc_rec->id.'-'.$mc_rec->office_id][$this->months[$month] . ' ' . $year] = $this->tab1Array($mc_rec);
                                    }
                                    elseif(isset($mc_all_data[$mc_rec->id.'-'.$mc_rec->office_id]) && !empty($mc_rec->office_id) && $month == 'year_total') {
                                        $worker_check = 1;
                                        $mc_all_data[$mc_rec->id.'-'.$mc_rec->office_id][$year . ' Total'] = $this->tab1Array($mc_rec);
                                    }
                                    elseif(isset($mc_all_data[$mc_rec->id.'-'.$mc_rec->office_id]) && !empty($mc_rec->office_id) && $month == 'grand_total') {
                                        $worker_check = 1;
                                        $mc_all_data[$mc_rec->id.'-'.$mc_rec->office_id]['Grand Total'] = $this->tab1Array($mc_rec);
                                    }
                                }

                                // ALL OTHERS DATA
                                if(($mc_rec->is_sales == 0 || $mc_rec->status == 0) && $search_type == 'employee') {
                                    $others_check = 1;
                                    if(!isset($others_all_data['others']) && $month != 'year_total' && $month != 'grand_total') {
                                        $others_all_data['others'] = [
                                            'Base' => '',
                                            'MC' => __('others'),
                                            $this->months[$month] . ' ' . $year => $this->tab1Array($mc_rec)
                                        ];
                                    }
                                    elseif(isset($others_all_data['others']) && isset($this->months[$month]) && !isset($others_all_data['others'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $others_all_data['others'][$this->months[$month] . ' ' . $year] = $this->tab1Array($mc_rec);
                                    }
                                    elseif(isset($others_all_data['others']) && !isset($others_all_data['others'][$year . ' Total']) && $month == 'year_total') {
                                        $others_all_data['others'][$year . ' Total'] = $this->tab1Array($mc_rec);
                                    }
                                    elseif(isset($others_all_data['others']) && !isset($others_all_data['others']['Grand Total']) && $month == 'grand_total') {
                                        $others_all_data['others']['Grand Total'] = $this->tab1Array($mc_rec);
                                    }
                                    elseif(isset($others_all_data['others']) && isset($this->months[$month]) && isset($others_all_data['others'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $others_all_data['others'][$this->months[$month] . ' ' . $year];
                                        $others_all_data['others'][$this->months[$month] . ' ' . $year] = $this->tab1Array($mc_rec, $existing_array);
                                    }
                                    elseif(isset($others_all_data['others']) && isset($others_all_data['others'][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $others_all_data['others'][$year . ' Total'];
                                        $others_all_data['others'][$year . ' Total'] = $this->tab1Array($mc_rec, $existing_array);
                                    }
                                    elseif(isset($others_all_data['others']) && isset($others_all_data['others']['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $others_all_data['others']['Grand Total'];
                                        $others_all_data['others']['Grand Total'] = $this->tab1Array($mc_rec, $existing_array);
                                    }
                                }

                                // IF THERE IS NO OTHERS DATA IN CURRENT ITERATION THEN ADD A DEFAULT DATA
                                if($worker_check == 1 && $others_check == 0) {
                                    if(!isset($others_all_data['others']) && $month != 'year_total' && $month != 'grand_total') {
                                        $others_all_data['others'] = [
                                            'Base' => '',
                                            'MC' => __('others'),
                                            $this->months[$month] . ' ' . $year => $this->tab1DefaultArray($mc_rec)
                                        ];
                                    }
                                    elseif(isset($others_all_data['others']) && isset($this->months[$month]) && !isset($others_all_data['others'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $others_all_data['others'][$this->months[$month] . ' ' . $year] = $this->tab1DefaultArray($mc_rec);
                                    }
                                    elseif(isset($others_all_data['others']) && !isset($others_all_data['others'][$year . ' Total']) && $month == 'year_total') {
                                        $others_all_data['others'][$year . ' Total'] = $this->tab1DefaultArray($mc_rec);
                                    }
                                    elseif(isset($others_all_data['others']) && !isset($others_all_data['others']['Grand Total']) && $month == 'grand_total') {
                                        $others_all_data['others']['Grand Total'] = $this->tab1DefaultArray($mc_rec);
                                    }
                                }

                                // ALL CITIES DATA
                                if($search_type == 'area') {
                                    if(!isset($cities_all_data[$mc_rec->city_name]) && !empty($mc_rec->city_name) && $month != 'year_total' && $month != 'grand_total') {
                                        $cities_all_data[$mc_rec->city_name] = [
                                            'Base' => $mc_rec->city_name,
                                            'MC' => __('total'),
                                            $this->months[$month] . ' ' . $year => $this->tab1Array($mc_rec)
                                        ];
                                    }
                                    elseif(isset($cities_all_data[$mc_rec->city_name]) && isset($this->months[$month]) && !isset($cities_all_data[$mc_rec->city_name][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $cities_all_data[$mc_rec->city_name][$this->months[$month] . ' ' . $year] = $this->tab1Array($mc_rec);
                                    }
                                    elseif(isset($cities_all_data[$mc_rec->city_name]) && !isset($cities_all_data[$mc_rec->city_name][$year . ' Total']) && $month == 'year_total') {
                                        $cities_all_data[$mc_rec->city_name][$year . ' Total'] = $this->tab1Array($mc_rec);
                                    }
                                    elseif(isset($cities_all_data[$mc_rec->city_name]) && !isset($cities_all_data[$mc_rec->city_name]['Grand Total']) && $month == 'grand_total') {
                                        $cities_all_data[$mc_rec->city_name]['Grand Total'] = $this->tab1Array($mc_rec);
                                    }
                                    elseif(isset($cities_all_data[$mc_rec->city_name]) && isset($this->months[$month]) && isset($cities_all_data[$mc_rec->city_name][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $cities_all_data[$mc_rec->city_name][$this->months[$month] . ' ' . $year];
                                        $cities_all_data[$mc_rec->city_name][$this->months[$month] . ' ' . $year] = $this->tab1Array($mc_rec, $existing_array);
                                    }
                                    elseif(isset($cities_all_data[$mc_rec->city_name]) && isset($cities_all_data[$mc_rec->city_name][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $cities_all_data[$mc_rec->city_name][$year . ' Total'];
                                        $cities_all_data[$mc_rec->city_name][$year . ' Total'] = $this->tab1Array($mc_rec, $existing_array);
                                    }
                                    elseif(isset($cities_all_data[$mc_rec->city_name]) && isset($cities_all_data[$mc_rec->city_name]['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $cities_all_data[$mc_rec->city_name]['Grand Total'];
                                        $cities_all_data[$mc_rec->city_name]['Grand Total'] = $this->tab1Array($mc_rec, $existing_array);
                                    }
                                }

                                // ALL OFFICES DATA
                                if($search_type == 'office') {
                                    if(!isset($offices_all_data[$mc_rec->office_name]) && !empty($mc_rec->office_name) && $month != 'year_total' && $month != 'grand_total') {
                                        $offices_all_data[$mc_rec->office_name] = [
                                            'Base' => $mc_rec->office_name,
                                            'MC' => __('total'),
                                            $this->months[$month] . ' ' . $year => $this->tab1Array($mc_rec)
                                        ];
                                    }
                                    elseif(isset($offices_all_data[$mc_rec->office_name]) && isset($this->months[$month]) && !isset($offices_all_data[$mc_rec->office_name][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $offices_all_data[$mc_rec->office_name][$this->months[$month] . ' ' . $year] = $this->tab1Array($mc_rec);
                                    }
                                    elseif(isset($offices_all_data[$mc_rec->office_name]) && !isset($offices_all_data[$mc_rec->office_name][$year . ' Total']) && $month == 'year_total') {
                                        $offices_all_data[$mc_rec->office_name][$year . ' Total'] = $this->tab1Array($mc_rec);
                                    }
                                    elseif(isset($offices_all_data[$mc_rec->office_name]) && !isset($offices_all_data[$mc_rec->office_name]['Grand Total']) && $month == 'grand_total') {
                                        $offices_all_data[$mc_rec->office_name]['Grand Total'] = $this->tab1Array($mc_rec);
                                    }
                                    elseif(isset($offices_all_data[$mc_rec->office_name]) && isset($this->months[$month]) && isset($offices_all_data[$mc_rec->office_name][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $offices_all_data[$mc_rec->office_name][$this->months[$month] . ' ' . $year];
                                        $offices_all_data[$mc_rec->office_name][$this->months[$month] . ' ' . $year] = $this->tab1Array($mc_rec, $existing_array);
                                    }
                                    elseif(isset($offices_all_data[$mc_rec->office_name]) && isset($offices_all_data[$mc_rec->office_name][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $offices_all_data[$mc_rec->office_name][$year . ' Total'];
                                        $offices_all_data[$mc_rec->office_name][$year . ' Total'] = $this->tab1Array($mc_rec, $existing_array);
                                    }
                                    elseif(isset($offices_all_data[$mc_rec->office_name]) && isset($offices_all_data[$mc_rec->office_name]['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $offices_all_data[$mc_rec->office_name]['Grand Total'];
                                        $offices_all_data[$mc_rec->office_name]['Grand Total'] = $this->tab1Array($mc_rec, $existing_array);
                                    }
                                }

                                // ALL TOTAL DATA
                                if(!isset($total_column_data['column_wise_total']) && $month != 'year_total' && $month != 'grand_total') {
                                    $total_column_data['column_wise_total'] = [
                                        'Base' => __('grand_total'),
                                        'MC' => '',
                                        $this->months[$month] . ' ' . $year => $this->tab1Array($mc_rec)
                                    ];
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($this->months[$month]) && !isset($total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                    $total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year] = $this->tab1Array($mc_rec);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && !isset($total_column_data['column_wise_total'][$year . ' Total']) && $month == 'year_total') {
                                    $total_column_data['column_wise_total'][$year . ' Total'] = $this->tab1Array($mc_rec);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && !isset($total_column_data['column_wise_total']['Grand Total']) && $month == 'grand_total') {
                                    $total_column_data['column_wise_total']['Grand Total'] = $this->tab1Array($mc_rec);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($this->months[$month]) && isset($total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                    $existing_array = $total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year];
                                    $total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year] = $this->tab1Array($mc_rec, $existing_array);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($total_column_data['column_wise_total'][$year . ' Total']) && $month == 'year_total') {
                                    $existing_array = $total_column_data['column_wise_total'][$year . ' Total'];
                                    // echo '<pre>';print_r($existing_array);print_r($mc_rec);echo '***********************************************';echo "<br/><br/>";
                                    $total_column_data['column_wise_total'][$year . ' Total'] = $this->tab1Array($mc_rec, $existing_array);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($total_column_data['column_wise_total']['Grand Total']) && $month == 'grand_total') {
                                    $existing_array = $total_column_data['column_wise_total']['Grand Total'];
                                    $total_column_data['column_wise_total']['Grand Total'] = $this->tab1Array($mc_rec, $existing_array);
                                }
                            }
                        }
                        else {
                            // ALL TOTAL DATA - DEFAULT DATA
                            if(!isset($total_column_data['column_wise_total']) && $month != 'year_total' && $month != 'grand_total') {
                                $total_column_data['column_wise_total'] = [
                                    'Base' => __('grand_total'),
                                    'MC' => '',
                                    $this->months[$month] . ' ' . $year => $this->tab1DefaultArray()
                                ];
                            }
                            elseif(isset($total_column_data['column_wise_total']) && isset($this->months[$month]) && !isset($total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                $total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year] = $this->tab1DefaultArray();
                            }
                            elseif(isset($total_column_data['column_wise_total']) && !isset($total_column_data['column_wise_total'][$year . ' Total']) && $month == 'year_total') {
                                $total_column_data['column_wise_total'][$year . ' Total'] = $this->tab1DefaultArray();
                            }
                            elseif(isset($total_column_data['column_wise_total']) && !isset($total_column_data['column_wise_total']['Grand Total']) && $month == 'grand_total') {
                                $total_column_data['column_wise_total']['Grand Total'] = $this->tab1DefaultArray();
                            }
                        }
                    }
                }
            }

            // dd($mc_all_data, $others_all_data, $cities_all_data, $offices_all_data, $total_column_data);

            if($search_type == 'employee' && count($mc_all_data) == 0 && count($others_all_data) == 0) {
                $total_column_data = [];
            }
            elseif($search_type == 'area' && count($cities_all_data) == 0) {
                $total_column_data = [];
            }
            elseif($search_type == 'office' && count($offices_all_data) == 0) {
                $total_column_data = [];
            }
            
            if(count($total_column_data)) {
                $indices = array_keys($total_column_data['column_wise_total']);
                unset($indices[0], $indices[1]);
                
                foreach($total_column_data['column_wise_total'] as $key => $val) {

                    if($key == "Base" || $key == 'MC') {
                        continue;
                    }

                    $indices_flipped = array_flip($indices);
                    foreach($mc_all_data as &$mc) {
                        $_indices = $indices_flipped;
                        $keys = array_diff_key($indices_flipped, $mc);
                        foreach($keys as $i => $v) {
                            $_indices[$i] = $this->tab1DefaultArray();
                        }
                        $mc = array_merge($_indices, $mc);
                    }
                    
                    foreach($others_all_data as &$od) {
                        $_indices = $indices_flipped;
                        $keys = array_diff_key($indices_flipped, $od);
                        foreach($keys as $i => $v) {
                            $_indices[$i] = $this->tab1DefaultArray();
                        }
                        $od = array_merge($_indices, $od);
                    }
                    
                    foreach($cities_all_data as &$cd) {
                        $_indices = $indices_flipped;
                        $keys = array_diff_key($indices_flipped, $cd);
                        foreach($keys as $i => $v) {
                            $_indices[$i] = $this->tab1DefaultArray();
                        }
                        $cd = array_merge($_indices, $cd);
                    }
                    
                    foreach($offices_all_data as &$ofd) {
                        $_indices = $indices_flipped;
                        $keys = array_diff_key($indices_flipped, $ofd);
                        foreach($keys as $i => $v) {
                            $_indices[$i] = $this->tab1DefaultArray();
                        }
                        $ofd = array_merge($_indices, $ofd);
                    }   
                }
            }

            // Flatten the associative array into a numerically indexed array for sorting
            $flattened = [];
            foreach ($mc_all_data as $key => $value) {
                $value['original_key'] = $key;
                $flattened[] = $value;
            }

            $offices_order = Office::where('status', 1)->orderBy('seq_no', 'ASC')->pluck('name')->toArray();

            // Sort the flattened array using the custom comparison function
            usort($flattened, function($a, $b) use($offices_order) {
                
                $baseA = $a['Base'];
                $baseB = $b['Base'];
                
                $posA = array_search($baseA, $offices_order);
                $posB = array_search($baseB, $offices_order);
                
                return $posA - $posB;
            });

            // Rebuild the original associative array structure
            $mc_sorted_data = [];
            foreach ($flattened as $item) {
                $originalKey = $item['original_key'];
                unset($item['original_key']);
                $mc_sorted_data[$originalKey] = $item;
            }
            // dd($mc_all_data);

            $data = [
                'search_type' => $search_type,
                'mc_data' => count($mc_sorted_data) ? $mc_sorted_data : (object)[],
                'others_data' => count($others_all_data) ? $others_all_data : (object)[],
                'area_data'   => count($cities_all_data) ? $cities_all_data : (object)[],
                'office_data' => count($offices_all_data) ? $offices_all_data : (object)[],
                'grand_total' => count($total_column_data) ? $total_column_data : (object)[],
            ];
            
	        if (count($data)) {
	            return $this->sendResponse($data, __('record_found'));
	        }

	        return $this->sendResponse([], __('record_not_found'));
	    } 
	    catch (Exception $e) {
	        $errorMessage = $e->getMessage();
            $errorFile = $e->getFile();
            $errorLine = $e->getLine();

            // Combine the error message with its location
            $errorDetails = [
                'message' => $errorMessage,
                'file' => $errorFile,
                'line' => $errorLine,
            ];

            // Assuming sendError is a method that can accept an array of error details
            return $this->sendError(__('something_went_wrong'), $errorDetails, 400);
	    }
    }

    private function tab1Data($request, $dateOrYear, $getDataBy = 'month') 
    {
        try {    
            $month = $year = '';
            if($getDataBy == 'month') {
                $month = $dateOrYear->format('m');
                $year = $dateOrYear->format('Y');
            }
            elseif($getDataBy == 'year') {
                $year = $dateOrYear;
            }

            $city_id = $request->input('city_id', '');
            $office_id = $request->input('office_id', '');
            $user_ids = $request->input('user_ids', '[]');
            $user_ids = is_array($user_ids) ? $user_ids : [];
    
            $query = DB::table('users as u');
            $query->selectRaw("
                u.id,
                u.is_sales,
                u.status,
                CONCAT(u.first_name,' ',u.last_name) as mc_name, 
                offices.id as office_id,
                offices.name as office_name,
                cities.id as city_id,
                cities.name as city_name,
                COALESCE(interview_main_count.interview_count, 0) AS interview_count,
                COALESCE(interview_main_contract.contract_count, 0) AS contract_count,
                COALESCE(interview_main_estimated_amount.estimated_amount, 0) AS estimated_amount,
                COALESCE(interview_main_order_price.order_price, 0) AS order_price
            ");
            
            $query->distinct();
    
            $yearCondInterviewForSelect = !empty($year) ? "EXTRACT(YEAR FROM int_det.interview_date) as year," : "";
            $monthCondInterviewForSelect = !empty($month) ? "EXTRACT(MONTH FROM int_det.interview_date) as month," : "";
            $yearCondInterviewForGroupBy = !empty($year) ? "EXTRACT(YEAR FROM int_det.interview_date)," : "";
            $monthCondInterviewForGroupBy = !empty($month) ? "EXTRACT(MONTH FROM int_det.interview_date)," : "";
    
            // INTERVIEW COUNT
            $yearConditionInterviewCount = !empty($year) ? "AND EXTRACT(YEAR FROM int_det.interview_date) = '" . $year . "'" : "";
            $monthConditionInterviewCount = !empty($month) ? "AND EXTRACT(MONTH FROM int_det.interview_date) = '" . $month . "' " : "";
            $query->leftJoin(
                DB::raw('(
                        SELECT 
                            int_det.interviewer1, ' .
                            $yearCondInterviewForSelect . ' ' .
                            $monthCondInterviewForSelect . '
                            COUNT(DISTINCT int.id) AS interview_count,
                            int_det.office_id
                        FROM 
                            interviews as int
                        LEFT OUTER JOIN (
                            SELECT
                                main.*
                            FROM
                                interviews_detail AS main
                            WHERE
                                deleted_at IS NULL
                                AND id = (
                                    SELECT
                                        MIN(id)
                                    FROM
                                        interviews_detail AS sub
                                    WHERE
                                        sub.deleted_at IS NULL
                                        AND main.interview_id = sub.interview_id
                                )
                        ) AS int_det ON
                            int.id = int_det.interview_id
                        INNER JOIN 
                            office_departments as od ON
                            int.office_departments_id = od.id AND od.department_id = '.$this->department_id.'
                        WHERE
                            int.deleted_at is NULL AND
                            int.project_category = 1 AND
                            int.result_type != 1 ' .
                            $yearConditionInterviewCount . ' ' .
                            $monthConditionInterviewCount . '
                        GROUP BY
                            int_det.interviewer1, ' .
                            $yearCondInterviewForGroupBy . ' ' .
                            $monthCondInterviewForGroupBy . '
                            int_det.office_id
                    ) as interview_main_count'), function($join) {
                    $join->on('u.id', '=', 'interview_main_count.interviewer1');
                }
            );
              
            // CONTRACT COUNT
            $yearConditionContractCount = !empty($year) ? "AND EXTRACT(YEAR FROM int_det.interview_date) = '" . $year . "'" : "";
            $monthConditionContractCount = !empty($month) ? "AND EXTRACT(MONTH FROM int_det.interview_date) = '" . $month . "' " : "";
            $query->leftJoin(
                DB::raw('(
                        SELECT 
                            int_det.interviewer1, ' .
                            $yearCondInterviewForSelect . ' ' .
                            $monthCondInterviewForSelect . '
                            COUNT(DISTINCT int.id) AS contract_count,
                            int_det.office_id
                        FROM 
                            interviews as int
                        LEFT OUTER JOIN (
                            select
                                main.*
                            from
                                interviews_detail as main
                            where
                                deleted_at is null
                                and id = (
                                select
                                    MIN(id)
                                from
                                    interviews_detail as sub
                                where
                                    sub.deleted_at is null
                                    and main.interview_id = sub.interview_id)
                        ) as int_det on
                            int.id = int_det.interview_id
                        INNER JOIN 
                            office_departments as od ON
                            int.office_departments_id = od.id AND od.department_id = '.$this->department_id.'
                        WHERE
                            int.deleted_at is NULL AND
                            int.project_category = 1 AND
                            int.result_type = 2 ' .
                            $yearConditionContractCount . ' ' .
                            $monthConditionContractCount . '
                        GROUP BY
                            int_det.interviewer1, ' .
                            $yearCondInterviewForGroupBy . ' ' .
                            $monthCondInterviewForGroupBy . '
                            int_det.office_id
                    ) as interview_main_contract'), function($join) {
                    $join->on('interview_main_count.office_id', '=', 'interview_main_contract.office_id');
                    $join->on('interview_main_count.interviewer1', '=', 'interview_main_contract.interviewer1');
                    if(!empty($year)) {
                        $join->where(DB::raw('interview_main_count.year'), '=', DB::raw('interview_main_contract.year'));
                    }
                    if(!empty($month)) {
                        $join->where(DB::raw('interview_main_count.month'), '=', DB::raw('interview_main_contract.month'));
                    }
                }
            );
            
            // ESTIMATED PRICE
            $yearCondEstimatedForSelect = !empty($year) ? "EXTRACT(YEAR FROM int_det2.interview_date) as year," : "";
            $monthCondEstimatedForSelect = !empty($month) ? "EXTRACT(MONTH FROM int_det2.interview_date) as month," : "";
            $yearConditionEstimatedPrice = !empty($year) ? "AND EXTRACT(YEAR FROM int_det2.interview_date) = '" . $year . "'" : "";
            $monthConditionEstimatedPrice = !empty($month) ? "AND EXTRACT(MONTH FROM int_det2.interview_date) = '" . $month . "' " : "";
            $yearCondEstimatedForGroupBy = !empty($year) ? "EXTRACT(YEAR FROM int_det2.interview_date)," : "";
            $monthCondEstimatedForGroupBy = !empty($month) ? "EXTRACT(MONTH FROM int_det2.interview_date)," : "";
            $query->leftJoin(
                DB::raw('(
                        SELECT 
                            int_det.interviewer1, ' .
                            $yearCondEstimatedForSelect . ' ' .
                            $monthCondEstimatedForSelect . '
                            SUM(int_det.estimated_amount) AS estimated_amount,
                            int_det.office_id
                        FROM 
                            interviews as int
                        LEFT OUTER JOIN (
                            select
                                main.*
                            from
                                interviews_detail as main
                            where
                                deleted_at is null
                                and id = (
                                    select
                                        MAX(id)
                                    from
                                        interviews_detail as sub
                                    where
                                        sub.deleted_at is null
                                        and main.interview_id = sub.interview_id
                                )
                        ) as int_det on int.id = int_det.interview_id
                        LEFT OUTER JOIN (
                            select
                                main.*
                            from
                                interviews_detail as main
                            where
                                deleted_at is null
                                and id = (
                                    select
                                        MIN(id)
                                    from
                                        interviews_detail as sub
                                    where
                                        sub.deleted_at is null
                                        and main.interview_id = sub.interview_id
                                )
                        ) as int_det2 on int.id = int_det2.interview_id
                        INNER JOIN 
                            office_departments as od ON
                            int.office_departments_id = od.id AND od.department_id = '.$this->department_id.'
                        WHERE
                            int.deleted_at is NULL AND
                            int.project_category = 1 AND
                            int.result_type != 1 ' .
                            $yearConditionEstimatedPrice . ' ' .
                            $monthConditionEstimatedPrice . '
                        GROUP BY
                            int_det.interviewer1, ' .
                            $yearCondEstimatedForGroupBy . ' ' .
                            $monthCondEstimatedForGroupBy . '
                            int_det.office_id
                    ) as interview_main_estimated_amount'), function($join) {
                    $join->on('interview_main_count.office_id', '=', 'interview_main_estimated_amount.office_id');
                    $join->on('interview_main_count.interviewer1', '=', 'interview_main_estimated_amount.interviewer1');
                }
            );
    
            // ORDER PRICE
            $yearConditionOrderPrice = !empty($year) ? "AND EXTRACT(YEAR FROM int_det.interview_date) = '" . $year . "'" : "";
            $monthConditionOrderPrice = !empty($month) ? "AND EXTRACT(MONTH FROM int_det.interview_date) = '" . $month . "' " : "";
            $query->leftJoin(
                DB::raw('(
                        SELECT 
                            int_det.interviewer1, ' .
                            $yearCondInterviewForSelect . ' ' .
                            $monthCondInterviewForSelect . '
                            SUM(psoz.interview_order_amount) AS order_price,
                            int_det.office_id
                        FROM 
                            interviews as int
                        LEFT OUTER JOIN (
                            select
                                main.*
                            from
                                interviews_detail as main
                            where
                                deleted_at is null
                                and id = (
                                select
                                    MIN(id)
                                from
                                    interviews_detail as sub
                                where
                                    sub.deleted_at is null
                                    and main.interview_id = sub.interview_id)
                        ) as int_det on
                            int.id = int_det.interview_id
                        LEFT JOIN project_sozoku as psoz ON
                            int.id = psoz.interview_id AND psoz.interview_order_amount IS NOT NULL
                        INNER JOIN 
                            office_departments as od ON
                            int.office_departments_id = od.id AND od.department_id = '.$this->department_id.'
                        WHERE
                            int.deleted_at is NULL AND
                            int.project_category = 1 AND
                            int.result_type = 2 ' .
                            $yearConditionOrderPrice . ' ' .
                            $monthConditionOrderPrice . '
                        GROUP BY
                            int_det.interviewer1, ' .
                            $yearCondInterviewForGroupBy . ' ' .
                            $monthCondInterviewForGroupBy . '
                            int_det.office_id
                    ) as interview_main_order_price'), function($join) {
                    $join->on('interview_main_count.office_id', '=', 'interview_main_order_price.office_id');
                    $join->on('interview_main_count.interviewer1', '=', 'interview_main_order_price.interviewer1');
                    if(!empty($year)) {
                        $join->where(DB::raw('interview_main_count.year'), '=', DB::raw('interview_main_order_price.year'));
                    }
                    if(!empty($month)) {
                        $join->where(DB::raw('interview_main_count.month'), '=', DB::raw('interview_main_order_price.month'));
                    }
                }
            );

            $query->leftJoin('offices', 'interview_main_count.office_id', '=', 'offices.id');
            $query->join('cities', 'offices.city_id', '=', 'cities.id');
            $query->join('office_departments as od', 'offices.id', '=', 'od.office_id');

            $query->where('u.department_id', $this->department_id);
            $query->whereNotIn('u.id', $this->js_admin_ids_array);
            $query->whereNull('u.deleted_at');
            // $query->where('u.is_sales', 1);

            $query->where('offices.status', 1);
            // $query->where('od.department_id', $this->department_id);
            $query->where('od.status', 1);
            if(!empty($city_id)) {
                $query->where('offices.city_id', $city_id);
            }
            if(!empty($office_id)) {
                $query->where('offices.id', $office_id);
            }
            if(!empty($user_ids) && count($user_ids)) {
                $query->whereIn('u.id', $user_ids);
            }
            $query->orderBy('offices.id');
            $query->orderBy('u.id');

            // echo $query->toSql();exit;
            $data = $query->get();
            
            // dd($data->toArray());
            return $data->toArray();
        } 
        catch (Exception $e) {
            $errorMessage = $e->getMessage();
            $errorFile = $e->getFile();
            $errorLine = $e->getLine();

            // Combine the error message with its location
            $errorDetails = [
                'message' => $errorMessage,
                'file' => $errorFile,
                'line' => $errorLine,
            ];

            // Assuming sendError is a method that can accept an array of error details
            return $this->sendError(__('something_went_wrong'), $errorDetails, 400);
        }
    }

    /* public function tab1(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'start_date' => 'required|date_format:Y-m',
                'end_date' => 'required|date_format:Y-m',
                'is_yearly_total' => 'in:1,0',
                'is_grand_total' => 'in:1,0',
                'search_type' => 'required|in:area,office,employee',
            ]);
        
            if ($validator->fails()) {
                $errors = $validator->errors();
                $error_array = [];
                if($errors->first('start_date')) {
                    $error_array['start_date'] = [$errors->first('start_date')];
                }
                if($errors->first('end_date')) {
                    $error_array['end_date'] = [$errors->first('end_date')];
                }
                if($errors->first('is_yearly_total')) {
                    $error_array['is_yearly_total'] = [$errors->first('is_yearly_total')];
                }
                if($errors->first('is_grand_total')) {
                    $error_array['is_grand_total'] = [$errors->first('is_grand_total')];
                }
                if($errors->first('search_type')) {
                    $error_array['search_type'] = [$errors->first('search_type')];
                }
                return $this->sendError(__('something_went_wrong'), $error_array, 422);
            }

            $search_type = $request->input('search_type', '');      // Possible values area, office, employee
            $is_yearly_total = $request->input('is_yearly_total', 0);
            $is_grand_total = $request->input('is_grand_total', 0);

            // Assuming $start_date and $end_date are in 'Y-m' format, e.g., '2023-12'
            $start_date = Carbon::createFromFormat('Y-m-d', $request->start_date.'-01');
            $end_date = Carbon::createFromFormat('Y-m-d', $request->end_date.'-01')->endOfMonth(); // Ensure the end date covers the end of the month

            $currentDate = $start_date->copy();
            $previousYear = $start_date->year;

            // RESPONSE DATA CODE
            $response_data = [];            
            // Initial run for Function
            $response_data[$currentDate->year][$currentDate->format('m')] = $this->tab1Data($request, $currentDate, 'month');
            // Move to the next month for the loop start
            $currentDate->addMonth();
            
            while ($currentDate->lessThanOrEqualTo($end_date)) {
                // Check if the year has changed, indicating the start of a new year
                if ($currentDate->year != $previousYear && $is_yearly_total == 1) {
                    // Run Function for total of year calculation before processing the new year
                    $response_data[$previousYear]['year_total'] = $this->tab1Data($request, $previousYear, 'year');
                    $previousYear = $currentDate->year;
                }

                // Run Function for the current month
                $response_data[$currentDate->year][$currentDate->format('m')] = $this->tab1Data($request, $currentDate, 'month');

                // Move to the next month
                $currentDate->addMonth();
            }

            // After completing the loop, check if Function needs to be called for the end date's year
            if ($end_date->year == $previousYear && $is_yearly_total == 1) {
                $response_data[$end_date->year]['year_total'] = $this->tab1Data($request, $end_date->year, 'year');
            }
            
            // For grand total row wise
            if($is_grand_total == 1) {
                $response_data['all_years']['grand_total'] = $this->tab1Data($request, $end_date->year, 'all_years');
            }
            // dd($response_data);

            // ALL DATA
            $mc_all_data = $others_all_data = $cities_all_data = $offices_all_data = $total_column_data = [];
            foreach($response_data as $year => $record) {
                
                if(is_array($record)) {
                    foreach($record as $month => $mc_records) {

                        if(is_array($mc_records)) {
                            foreach($mc_records as $mc_rec) {
                                // ALL MCs DATA
                                if($mc_rec->is_sales == 1 && $mc_rec->status == 1 && $mc_rec->office_deleted == null && $search_type == 'employee') {
                                    if(!isset($mc_all_data[$mc_rec->id.'-'.$mc_rec->office_id]) && $month != 'year_total' && $month != 'grand_total') {
                                        $mc_all_data[$mc_rec->id.'-'.$mc_rec->office_id] = [
                                            'Base' => $mc_rec->office_name,
                                            'MC' => $mc_rec->mc_name,
                                            $this->months[$month] . ' ' . $year => $this->tab1Array($mc_rec)
                                        ];
                                    }
                                    elseif(isset($mc_all_data[$mc_rec->id.'-'.$mc_rec->office_id]) && $month != 'year_total' && $month != 'grand_total') {
                                        $mc_all_data[$mc_rec->id.'-'.$mc_rec->office_id][$this->months[$month] . ' ' . $year] = $this->tab1Array($mc_rec);
                                    }
                                    elseif(isset($mc_all_data[$mc_rec->id.'-'.$mc_rec->office_id]) && $month == 'year_total') {
                                        $mc_all_data[$mc_rec->id.'-'.$mc_rec->office_id][$year . ' Total'] = $this->tab1Array($mc_rec);
                                    }
                                    elseif(isset($mc_all_data[$mc_rec->id.'-'.$mc_rec->office_id]) && $month == 'grand_total') {
                                        $mc_all_data[$mc_rec->id.'-'.$mc_rec->office_id]['Grand Total'] = $this->tab1Array($mc_rec);
                                    }
                                }

                                // ALL OTHERS DATA
                                if(($mc_rec->is_sales == 0 || $mc_rec->status == 0 || $mc_rec->office_deleted != null) && $search_type == 'employee') {
                                    if(!isset($others_all_data['others']) && $month != 'year_total' && $month != 'grand_total') {
                                        $others_all_data['others'] = [
                                            'Base' => '',
                                            'MC' => __('others'),
                                            $this->months[$month] . ' ' . $year => $this->tab1Array($mc_rec)
                                        ];
                                    }
                                    elseif(isset($others_all_data['others']) && isset($this->months[$month]) && !isset($others_all_data['others'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $others_all_data['others'][$this->months[$month] . ' ' . $year] = $this->tab1Array($mc_rec);
                                    }
                                    elseif(isset($others_all_data['others']) && !isset($others_all_data['others'][$year . ' Total']) && $month == 'year_total') {
                                        $others_all_data['others'][$year . ' Total'] = $this->tab1Array($mc_rec);
                                    }
                                    elseif(isset($others_all_data['others']) && !isset($others_all_data['others']['Grand Total']) && $month == 'grand_total') {
                                        $others_all_data['others']['Grand Total'] = $this->tab1Array($mc_rec);
                                    }
                                    elseif(isset($others_all_data['others']) && isset($this->months[$month]) && isset($others_all_data['others'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $others_all_data['others'][$this->months[$month] . ' ' . $year];
                                        $others_all_data['others'][$this->months[$month] . ' ' . $year] = $this->tab1Array($mc_rec, $existing_array);
                                    }
                                    elseif(isset($others_all_data['others']) && isset($others_all_data['others'][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $others_all_data['others'][$year . ' Total'];
                                        $others_all_data['others'][$year . ' Total'] = $this->tab1Array($mc_rec, $existing_array);
                                    }
                                    elseif(isset($others_all_data['others']) && isset($others_all_data['others']['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $others_all_data['others']['Grand Total'];
                                        $others_all_data['others']['Grand Total'] = $this->tab1Array($mc_rec, $existing_array);
                                    }
                                }

                                // ALL CITIES DATA
                                if($search_type == 'area') {
                                    if(!isset($cities_all_data[$mc_rec->city_name]) && $month != 'year_total' && $month != 'grand_total') {
                                        $cities_all_data[$mc_rec->city_name] = [
                                            'Base' => $mc_rec->city_name,
                                            'MC' => __('total'),
                                            $this->months[$month] . ' ' . $year => $this->tab1Array($mc_rec)
                                        ];
                                    }
                                    elseif(isset($cities_all_data[$mc_rec->city_name]) && isset($this->months[$month]) && !isset($cities_all_data[$mc_rec->city_name][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $cities_all_data[$mc_rec->city_name][$this->months[$month] . ' ' . $year] = $this->tab1Array($mc_rec);
                                    }
                                    elseif(isset($cities_all_data[$mc_rec->city_name]) && !isset($cities_all_data[$mc_rec->city_name][$year . ' Total']) && $month == 'year_total') {
                                        $cities_all_data[$mc_rec->city_name][$year . ' Total'] = $this->tab1Array($mc_rec);
                                    }
                                    elseif(isset($cities_all_data[$mc_rec->city_name]) && !isset($cities_all_data[$mc_rec->city_name]['Grand Total']) && $month == 'grand_total') {
                                        $cities_all_data[$mc_rec->city_name]['Grand Total'] = $this->tab1Array($mc_rec);
                                    }
                                    elseif(isset($cities_all_data[$mc_rec->city_name]) && isset($this->months[$month]) && isset($cities_all_data[$mc_rec->city_name][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $cities_all_data[$mc_rec->city_name][$this->months[$month] . ' ' . $year];
                                        $cities_all_data[$mc_rec->city_name][$this->months[$month] . ' ' . $year] = $this->tab1Array($mc_rec, $existing_array);
                                    }
                                    elseif(isset($cities_all_data[$mc_rec->city_name]) && isset($cities_all_data[$mc_rec->city_name][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $cities_all_data[$mc_rec->city_name][$year . ' Total'];
                                        $cities_all_data[$mc_rec->city_name][$year . ' Total'] = $this->tab1Array($mc_rec, $existing_array);
                                    }
                                    elseif(isset($cities_all_data[$mc_rec->city_name]) && isset($cities_all_data[$mc_rec->city_name]['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $cities_all_data[$mc_rec->city_name]['Grand Total'];
                                        $cities_all_data[$mc_rec->city_name]['Grand Total'] = $this->tab1Array($mc_rec, $existing_array);
                                    }
                                }

                                // ALL OFFICES DATA
                                if($search_type == 'office') {
                                    if(!isset($offices_all_data[$mc_rec->office_name]) && $month != 'year_total' && $month != 'grand_total') {
                                        $offices_all_data[$mc_rec->office_name] = [
                                            'Base' => $mc_rec->office_name,
                                            'MC' => __('total'),
                                            $this->months[$month] . ' ' . $year => $this->tab1Array($mc_rec)
                                        ];
                                    }
                                    elseif(isset($offices_all_data[$mc_rec->office_name]) && isset($this->months[$month]) && !isset($offices_all_data[$mc_rec->office_name][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $offices_all_data[$mc_rec->office_name][$this->months[$month] . ' ' . $year] = $this->tab1Array($mc_rec);
                                    }
                                    elseif(isset($offices_all_data[$mc_rec->office_name]) && !isset($offices_all_data[$mc_rec->office_name][$year . ' Total']) && $month == 'year_total') {
                                        $offices_all_data[$mc_rec->office_name][$year . ' Total'] = $this->tab1Array($mc_rec);
                                    }
                                    elseif(isset($offices_all_data[$mc_rec->office_name]) && !isset($offices_all_data[$mc_rec->office_name]['Grand Total']) && $month == 'grand_total') {
                                        $offices_all_data[$mc_rec->office_name]['Grand Total'] = $this->tab1Array($mc_rec);
                                    }
                                    elseif(isset($offices_all_data[$mc_rec->office_name]) && isset($this->months[$month]) && isset($offices_all_data[$mc_rec->office_name][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $offices_all_data[$mc_rec->office_name][$this->months[$month] . ' ' . $year];
                                        $offices_all_data[$mc_rec->office_name][$this->months[$month] . ' ' . $year] = $this->tab1Array($mc_rec, $existing_array);
                                    }
                                    elseif(isset($offices_all_data[$mc_rec->office_name]) && isset($offices_all_data[$mc_rec->office_name][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $offices_all_data[$mc_rec->office_name][$year . ' Total'];
                                        $offices_all_data[$mc_rec->office_name][$year . ' Total'] = $this->tab1Array($mc_rec, $existing_array);
                                    }
                                    elseif(isset($offices_all_data[$mc_rec->office_name]) && isset($offices_all_data[$mc_rec->office_name]['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $offices_all_data[$mc_rec->office_name]['Grand Total'];
                                        $offices_all_data[$mc_rec->office_name]['Grand Total'] = $this->tab1Array($mc_rec, $existing_array);
                                    }
                                }

                                // ALL TOTAL DATA
                                if(!isset($total_column_data['column_wise_total']) && $month != 'year_total' && $month != 'grand_total') {
                                    $total_column_data['column_wise_total'] = [
                                        'Base' => __('grand_total'),
                                        'MC' => '',
                                        $this->months[$month] . ' ' . $year => $this->tab1Array($mc_rec)
                                    ];
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($this->months[$month]) && !isset($total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                    $total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year] = $this->tab1Array($mc_rec);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && !isset($total_column_data['column_wise_total'][$year . ' Total']) && $month == 'year_total') {
                                    $total_column_data['column_wise_total'][$year . ' Total'] = $this->tab1Array($mc_rec);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && !isset($total_column_data['column_wise_total']['Grand Total']) && $month == 'grand_total') {
                                    $total_column_data['column_wise_total']['Grand Total'] = $this->tab1Array($mc_rec);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($this->months[$month]) && isset($total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                    $existing_array = $total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year];
                                    $total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year] = $this->tab1Array($mc_rec, $existing_array);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($total_column_data['column_wise_total'][$year . ' Total']) && $month == 'year_total') {
                                    $existing_array = $total_column_data['column_wise_total'][$year . ' Total'];
                                    $total_column_data['column_wise_total'][$year . ' Total'] = $this->tab1Array($mc_rec, $existing_array);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($total_column_data['column_wise_total']['Grand Total']) && $month == 'grand_total') {
                                    $existing_array = $total_column_data['column_wise_total']['Grand Total'];
                                    $total_column_data['column_wise_total']['Grand Total'] = $this->tab1Array($mc_rec, $existing_array);
                                }
                            }
                        }
                    }
                }
            }

            $data = [
                'mc_data' => count($mc_all_data) ? $mc_all_data : (object)[],
                'others_data' => count($others_all_data) ? $others_all_data : (object)[],
                'area_data'   => count($cities_all_data) ? $cities_all_data : (object)[],
                'office_data' => count($offices_all_data) ? $offices_all_data : (object)[],
                'grand_total' => count($total_column_data) ? $total_column_data : (object)[],
            ];
            
	        if (count($data)) {
	            return $this->sendResponse($data, __('record_found'));
	        }

	        return $this->sendResponse([], __('record_not_found'));
	    } 
	    catch (Exception $e) {
	        $errorMessage = $e->getMessage();
            $errorFile = $e->getFile();
            $errorLine = $e->getLine();

            // Combine the error message with its location
            $errorDetails = [
                'message' => $errorMessage,
                'file' => $errorFile,
                'line' => $errorLine,
            ];

            // Assuming sendError is a method that can accept an array of error details
            return $this->sendError(__('something_went_wrong'), $errorDetails, 400);
	    }
    }

    private function tab1Data($request, $dateOrYear, $getDataBy = 'month') 
    {
        try {    
            $month = $year = '';
            if($getDataBy == 'month') {
                $month = $dateOrYear->format('m');
                $year = $dateOrYear->format('Y');
            }
            elseif($getDataBy == 'year') {
                $year = $dateOrYear;
            }

            $city_id = $request->input('city_id', '');
            $office_id = $request->input('office_id', '');
            $user_ids = $request->input('user_ids', '[]');
            $user_ids = is_array($user_ids) ? $user_ids : [];
    
            $query = DB::table('users as u');
            $query->selectRaw("
                u.id,
                u.is_sales,
                u.status,
                user_offices.deleted_at as office_deleted,
                CONCAT(u.first_name,' ',u.last_name) as mc_name, 
                CONCAT(u.first_name_kana,' ',u.last_name_kana) as mc_name_kana, 
                offices.id as office_id,
                offices.name as office_name,
                cities.id as city_id,
                cities.name as city_name,
                COALESCE(interview_main_count.interview_count, 0) AS interview_count,
                COALESCE(interview_main_contract.contract_count, 0) AS contract_count,
                COALESCE(interview_main_estimated_amount.estimated_amount, 0) AS estimated_amount,
                COALESCE(interview_main_order_price.order_price, 0) AS order_price
            ");
            
            $query->distinct();
            $query->join('user_offices', 'u.id', '=', 'user_offices.user_id');
            $query->join('offices', 'user_offices.office_id', '=', 'offices.id');
            $query->join('cities', 'offices.city_id', '=', 'cities.id');
    
            $yearCondInterviewForSelect = !empty($year) ? "EXTRACT(YEAR FROM int_det.interview_date) as year," : "";
            $monthCondInterviewForSelect = !empty($month) ? "EXTRACT(MONTH FROM int_det.interview_date) as month," : "";
            $yearCondInterviewForGroupBy = !empty($year) ? "EXTRACT(YEAR FROM int_det.interview_date)," : "";
            $monthCondInterviewForGroupBy = !empty($month) ? "EXTRACT(MONTH FROM int_det.interview_date)," : "";
    
            // INTERVIEW COUNT
            $yearConditionInterviewCount = !empty($year) ? "AND EXTRACT(YEAR FROM int_det.interview_date) = '" . $year . "'" : "";
            $monthConditionInterviewCount = !empty($month) ? "AND EXTRACT(MONTH FROM int_det.interview_date) = '" . $month . "' " : "";
            $query->leftJoin(
                DB::raw('(
                        SELECT 
                            int_det.interviewer1, ' .
                            $yearCondInterviewForSelect . ' ' .
                            $monthCondInterviewForSelect . '
                            COUNT(DISTINCT int.id) AS interview_count,
                            int_det.office_id
                        FROM 
                            interviews as int
                        LEFT JOIN (
                            SELECT 
                                int_det_sub.*
                            FROM
                                interviews_detail as int_det_sub
                            WHERE
                                int_det_sub.deleted_at is NULL AND
                                int_det_sub.id = (
                                    SELECT 
                                        id
                                    FROM 
                                        interviews_detail as int_det_min
                                    WHERE
                                        int_det_min.deleted_at is NULL AND
                                        int_det_sub.id = int_det_min.id
                                    ORDER BY int_det_min.created_at ASC
                                    LIMIT 1
                                )
                        ) as int_det ON int.id = int_det.interview_id
                        INNER JOIN 
                            office_departments as od ON
                            int.office_departments_id = od.id AND od.department_id = '.$this->department_id.'
                        WHERE
                            int.deleted_at is NULL AND
                            int.project_category = 1 AND
                            int.result_type != 1 ' .
                            $yearConditionInterviewCount . ' ' .
                            $monthConditionInterviewCount . '
                        GROUP BY
                            int_det.interviewer1, ' .
                            $yearCondInterviewForGroupBy . ' ' .
                            $monthCondInterviewForGroupBy . '
                            int_det.office_id
                    ) as interview_main_count'), function($join) {
                    $join->on('u.id', '=', 'interview_main_count.interviewer1');
                    $join->on(DB::raw('offices.id'), '=', DB::raw('interview_main_count.office_id'));
                }
            );
              
            // CONTRACT COUNT
            $yearConditionContractCount = !empty($year) ? "AND EXTRACT(YEAR FROM int_det.interview_date) = '" . $year . "'" : "";
            $monthConditionContractCount = !empty($month) ? "AND EXTRACT(MONTH FROM int_det.interview_date) = '" . $month . "' " : "";
            $query->leftJoin(
                DB::raw('(
                        SELECT 
                            int_det.interviewer1, ' .
                            $yearCondInterviewForSelect . ' ' .
                            $monthCondInterviewForSelect . '
                            COUNT(DISTINCT int.id) AS contract_count,
                            int_det.office_id
                        FROM 
                            interviews as int
                        LEFT JOIN (
                            SELECT 
                                int_det_sub.*
                            FROM
                                interviews_detail as int_det_sub
                            WHERE
                                int_det_sub.deleted_at is NULL AND
                                int_det_sub.id = (
                                    SELECT 
                                        id
                                    FROM 
                                        interviews_detail as int_det_min
                                    WHERE
                                        int_det_min.deleted_at is NULL AND
                                        int_det_sub.id = int_det_min.id
                                    ORDER BY int_det_min.created_at ASC
                                    LIMIT 1
                                )
                        ) as int_det ON int.id = int_det.interview_id
                        INNER JOIN 
                            office_departments as od ON
                            int.office_departments_id = od.id AND od.department_id = '.$this->department_id.'
                        WHERE
                            int.deleted_at is NULL AND
                            int.project_category = 1 AND
                            int.result_type = 2 ' .
                            $yearConditionContractCount . ' ' .
                            $monthConditionContractCount . '
                        GROUP BY
                            int_det.interviewer1, ' .
                            $yearCondInterviewForGroupBy . ' ' .
                            $monthCondInterviewForGroupBy . '
                            int_det.office_id
                    ) as interview_main_contract'), function($join) {
                    $join->on('interview_main_count.interviewer1', '=', 'interview_main_contract.interviewer1');
                    if(!empty($year)) {
                        $join->where(DB::raw('interview_main_count.year'), '=', DB::raw('interview_main_contract.year'));
                    }
                    if(!empty($month)) {
                        $join->where(DB::raw('interview_main_count.month'), '=', DB::raw('interview_main_contract.month'));
                    }
                    $join->where(DB::raw('offices.id'), '=', DB::raw('interview_main_contract.office_id'));
                }
            );
            
            // ESTIMATED PRICE
            $yearConditionEstimatedPrice = !empty($year) ? "AND EXTRACT(YEAR FROM int_det.interview_date) = '" . $year . "'" : "";
            $monthConditionEstimatedPrice = !empty($month) ? "AND EXTRACT(MONTH FROM int_det.interview_date) = '" . $month . "' " : "";
            $query->leftJoin(
                DB::raw('(
                        SELECT 
                            int_det.interviewer1, ' .
                            $yearCondInterviewForSelect . ' ' .
                            $monthCondInterviewForSelect . '
                            SUM(int_det.estimated_amount) AS estimated_amount,
                            int_det.office_id
                        FROM 
                            interviews as int
                        LEFT JOIN (
                            SELECT 
                                int_det_sub.*
                            FROM
                                interviews_detail as int_det_sub
                            WHERE
                                int_det_sub.deleted_at is NULL AND
                                int_det_sub.id = (
                                    SELECT 
                                        id
                                    FROM 
                                        interviews_detail as int_det_min
                                    WHERE
                                        int_det_min.deleted_at is NULL AND
                                        int_det_sub.id = int_det_min.id
                                    ORDER BY int_det_min.created_at DESC
                                    LIMIT 1
                                )
                        ) as int_det ON int.id = int_det.interview_id
                        INNER JOIN 
                            office_departments as od ON
                            int.office_departments_id = od.id AND od.department_id = '.$this->department_id.'
                        WHERE
                            int.deleted_at is NULL AND
                            int.project_category = 1 AND
                            int.result_type != 1 ' .
                            $yearConditionEstimatedPrice . ' ' .
                            $monthConditionEstimatedPrice . '
                        GROUP BY
                            int_det.interviewer1, ' .
                            $yearCondInterviewForGroupBy . ' ' .
                            $monthCondInterviewForGroupBy . '
                            int_det.office_id
                    ) as interview_main_estimated_amount'), function($join) {
                    $join->on('interview_main_count.interviewer1', '=', 'interview_main_estimated_amount.interviewer1');
                    $join->where(DB::raw('offices.id'), '=', DB::raw('interview_main_estimated_amount.office_id'));
                }
            );
    
            // ORDER PRICE
            $yearConditionOrderPrice = !empty($year) ? "AND EXTRACT(YEAR FROM int_det.interview_date) = '" . $year . "'" : "";
            $monthConditionOrderPrice = !empty($month) ? "AND EXTRACT(MONTH FROM int_det.interview_date) = '" . $month . "' " : "";
            $query->leftJoin(
                DB::raw('(
                        SELECT 
                            int_det.interviewer1, ' .
                            $yearCondInterviewForSelect . ' ' .
                            $monthCondInterviewForSelect . '
                            SUM(psoz.interview_order_amount) AS order_price,
                            int_det.office_id
                        FROM 
                            interviews as int
                        LEFT JOIN (
                            SELECT 
                                int_det_sub.*
                            FROM
                                interviews_detail as int_det_sub
                            WHERE
                                int_det_sub.deleted_at is NULL AND
                                int_det_sub.id = (
                                    SELECT 
                                        id
                                    FROM 
                                        interviews_detail as int_det_min
                                    WHERE
                                        int_det_min.deleted_at is NULL AND
                                        int_det_sub.id = int_det_min.id
                                    ORDER BY int_det_min.created_at ASC
                                    LIMIT 1
                                )
                        ) as int_det ON int.id = int_det.interview_id
                        LEFT JOIN project_sozoku as psoz ON
                            int.id = psoz.interview_id
                        INNER JOIN 
                            office_departments as od ON
                            int.office_departments_id = od.id AND od.department_id = '.$this->department_id.'
                        WHERE
                            int.deleted_at is NULL AND
                            int.project_category = 1 AND
                            int.result_type = 2 ' .
                            $yearConditionOrderPrice . ' ' .
                            $monthConditionOrderPrice . '
                        GROUP BY
                            int_det.interviewer1, ' .
                            $yearCondInterviewForGroupBy . ' ' .
                            $monthCondInterviewForGroupBy . '
                            int_det.office_id
                    ) as interview_main_order_price'), function($join) {
                    $join->on('interview_main_count.interviewer1', '=', 'interview_main_order_price.interviewer1');
                    if(!empty($year)) {
                        $join->where(DB::raw('interview_main_count.year'), '=', DB::raw('interview_main_order_price.year'));
                    }
                    if(!empty($month)) {
                        $join->where(DB::raw('interview_main_count.month'), '=', DB::raw('interview_main_order_price.month'));
                    }
                    $join->where(DB::raw('offices.id'), '=', DB::raw('interview_main_order_price.office_id'));
                }
            );
            
            $query->where('u.department_id', $this->department_id);
            $query->whereNotIn('u.id', $this->js_admin_ids_array);
            $query->whereNull('u.deleted_at');
            if(!empty($city_id)) {
                $query->where('offices.city_id', $city_id);
            }
            if(!empty($office_id)) {
                $query->where('offices.id', $office_id);
            }
            if(!empty($user_ids) && count($user_ids)) {
                $query->whereIn('u.id', $user_ids);
            }
            $query->orderBy('offices.id');
            $query->orderBy('u.id');
            $data = $query->get();
            // dd($data);
    
            return $data->toArray();
        } 
        catch (Exception $e) {
            $errorMessage = $e->getMessage();
            $errorFile = $e->getFile();
            $errorLine = $e->getLine();

            // Combine the error message with its location
            $errorDetails = [
                'message' => $errorMessage,
                'file' => $errorFile,
                'line' => $errorLine,
            ];

            // Assuming sendError is a method that can accept an array of error details
            return $this->sendError(__('something_went_wrong'), $errorDetails, 400);
        }
    } */

    /**
     * Current loop record - $cur_rec
     * Existing record - $ex_rec
     */
    private function tab1Array($cur_rec, $ex_rec = [])
    {
        if(count($ex_rec)) {
            $_interview_count = $cur_rec->interview_count + $ex_rec['interview_count'];
            $_contract_count = $cur_rec->contract_count + $ex_rec['contract_count'];
                                                    
            $_estimated_amount = str_replace(",", "", $cur_rec->estimated_amount) + str_replace(",", "", $ex_rec['estimated_amount']);
            $_order_price = str_replace(",", "", $cur_rec->order_price) + str_replace(",", "", $ex_rec['order_price']);

            $interview = [
                'interview_count'   => $_interview_count,
                'contract_count'    => $_contract_count,
                'contract_rate'     => $this->division($_contract_count, $_interview_count),
                'estimated_amount'  => number_format($_estimated_amount, 0, '.', ','),
                'order_price'       => number_format($_order_price, 0, '.', ','),
            ];
        }
        else {
            $interview = [
                'interview_count'   => $cur_rec->interview_count,
                'contract_count'    => $cur_rec->contract_count,
                'contract_rate'     => $this->division($cur_rec->contract_count, $cur_rec->interview_count),
                'estimated_amount'  => number_format($cur_rec->estimated_amount, 0, '.', ','),
                'order_price'       => number_format($cur_rec->order_price, 0, '.', ','),
            ];
        }

        return $interview;
    }

    private function tab1DefaultArray() 
    {
        return [
            "interview_count" => 0,
            "contract_count" => 0,
            "contract_rate" => "0%",
            "estimated_amount" => "0",
            "order_price" => "0",
        ];
    }

    // TAB 1a
    public function tab1a(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'start_date' => 'required|date_format:Y-m',
                'end_date' => 'required|date_format:Y-m',
                'is_yearly_total' => 'in:1,0',
                'is_grand_total' => 'in:1,0',
                'search_type' => 'required|in:area,office,employee',
            ]);
        
            if ($validator->fails()) {
                $errors = $validator->errors();
                $error_array = [];
                if($errors->first('start_date')) {
                    $error_array['start_date'] = [$errors->first('start_date')];
                }
                if($errors->first('end_date')) {
                    $error_array['end_date'] = [$errors->first('end_date')];
                }
                if($errors->first('is_yearly_total')) {
                    $error_array['is_yearly_total'] = [$errors->first('is_yearly_total')];
                }
                if($errors->first('is_grand_total')) {
                    $error_array['is_grand_total'] = [$errors->first('is_grand_total')];
                }
                if($errors->first('search_type')) {
                    $error_array['search_type'] = [$errors->first('search_type')];
                }
                return $this->sendError(__('something_went_wrong'), $error_array, 422);
            }

            $search_type = $request->input('search_type', '');      // Possible values area, office, employee
            $is_yearly_total = $request->input('is_yearly_total', 0);
            $is_grand_total = $request->input('is_grand_total', 0);

            // Assuming $start_date and $end_date are in 'Y-m' format, e.g., '2023-12'
            $start_date = Carbon::createFromFormat('Y-m-d', $request->start_date.'-01');
            $end_date = Carbon::createFromFormat('Y-m-d', $request->end_date.'-01')->endOfMonth(); // Ensure the end date covers the end of the month

            $currentDate = $start_date->copy();
            $previousYear = $start_date->year;

            // RESPONSE DATA CODE
            $response_data = [];            
            // Initial run for Function
            $response_data[$currentDate->year][$currentDate->format('m')] = $this->tab1aData($request, $currentDate, 'month');
            // Move to the next month for the loop start
            $currentDate->addMonth();
            
            while ($currentDate->lessThanOrEqualTo($end_date)) {
                // Check if the year has changed, indicating the start of a new year
                if ($currentDate->year != $previousYear && $is_yearly_total == 1) {
                    // Run Function for total of year calculation before processing the new year
                    $response_data[$previousYear]['year_total'] = $this->tab1aData($request, $previousYear, 'year');
                    $previousYear = $currentDate->year;
                }

                // Run Function for the current month
                $response_data[$currentDate->year][$currentDate->format('m')] = $this->tab1aData($request, $currentDate, 'month');

                // Move to the next month
                $currentDate->addMonth();
            }

            // After completing the loop, check if Function needs to be called for the end date's year
            if ($end_date->year == $previousYear && $is_yearly_total == 1) {
                $response_data[$end_date->year]['year_total'] = $this->tab1aData($request, $end_date->year, 'year');
            }
            
            // For grand total row wise
            if($is_grand_total == 1) {
                $response_data['all_years']['grand_total'] = $this->tab1aData($request, $end_date->year, 'all_years');
            }
            // dd($response_data);

            // ALL DATA
            $mc_all_data = $others_all_data = $cities_all_data = $offices_all_data = $total_column_data = [];
            foreach($response_data as $year => $record) {
                
                if(is_array($record)) {
                    foreach($record as $month => $mc_records) {

                        if(is_array($mc_records)) {
                            foreach($mc_records as $mc_rec) {
                                $worker_check = $others_check = 0;

                                // ALL MCs DATA
                                // if($mc_rec->is_sales == 1 && $mc_rec->status == 1 && empty($mc_rec->office_deleted) && $search_type == 'employee') {
                                if($mc_rec->is_sales == 1 && $mc_rec->status == 1 && $search_type == 'employee') {
                                    $worker_check = 1;
                                    if(!isset($mc_all_data[$mc_rec->id.'-'.$mc_rec->office_id]) && $month != 'year_total' && $month != 'grand_total') {
                                        $mc_all_data[$mc_rec->id.'-'.$mc_rec->office_id] = [
                                            'Base' => $mc_rec->office_name,
                                            'MC' => $mc_rec->mc_name,
                                            $this->months[$month] . ' ' . $year => $this->tab1aArray($mc_rec)
                                        ];
                                    }
                                    elseif(isset($mc_all_data[$mc_rec->id.'-'.$mc_rec->office_id]) && $month != 'year_total' && $month != 'grand_total') {
                                        $mc_all_data[$mc_rec->id.'-'.$mc_rec->office_id][$this->months[$month] . ' ' . $year] = $this->tab1aArray($mc_rec);
                                    }
                                    elseif(isset($mc_all_data[$mc_rec->id.'-'.$mc_rec->office_id]) && $month == 'year_total') {
                                        $mc_all_data[$mc_rec->id.'-'.$mc_rec->office_id][$year . ' Total'] = $this->tab1aArray($mc_rec);
                                    }
                                    elseif(isset($mc_all_data[$mc_rec->id.'-'.$mc_rec->office_id]) && $month == 'grand_total') {
                                        $mc_all_data[$mc_rec->id.'-'.$mc_rec->office_id]['Grand Total'] = $this->tab1aArray($mc_rec);
                                    }
                                }

                                // ALL OTHERS DATA
                                // if(($mc_rec->is_sales == 0 || $mc_rec->status == 0 || !empty($mc_rec->office_deleted)) && $search_type == 'employee') {
                                if(($mc_rec->is_sales == 0 || $mc_rec->status == 0) && $search_type == 'employee') {
                                    $others_check = 1;
                                    if(!isset($others_all_data['others']) && $month != 'year_total' && $month != 'grand_total') {
                                        $others_all_data['others'] = [
                                            'Base' => '',
                                            'MC' => __('others'),
                                            $this->months[$month] . ' ' . $year => $this->tab1aArray($mc_rec)
                                        ];
                                    }
                                    elseif(isset($others_all_data['others']) && isset($this->months[$month]) && !isset($others_all_data['others'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $others_all_data['others'][$this->months[$month] . ' ' . $year] = $this->tab1aArray($mc_rec);
                                    }
                                    elseif(isset($others_all_data['others']) && !isset($others_all_data['others'][$year . ' Total']) && $month == 'year_total') {
                                        $others_all_data['others'][$year . ' Total'] = $this->tab1aArray($mc_rec);
                                    }
                                    elseif(isset($others_all_data['others']) && !isset($others_all_data['others']['Grand Total']) && $month == 'grand_total') {
                                        $others_all_data['others']['Grand Total'] = $this->tab1aArray($mc_rec);
                                    }
                                    elseif(isset($others_all_data['others']) && isset($this->months[$month]) && isset($others_all_data['others'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $others_all_data['others'][$this->months[$month] . ' ' . $year];
                                        $others_all_data['others'][$this->months[$month] . ' ' . $year] = $this->tab1aArray($mc_rec, $existing_array);
                                    }
                                    elseif(isset($others_all_data['others']) && isset($others_all_data['others'][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $others_all_data['others'][$year . ' Total'];
                                        $others_all_data['others'][$year . ' Total'] = $this->tab1aArray($mc_rec, $existing_array);
                                    }
                                    elseif(isset($others_all_data['others']) && isset($others_all_data['others']['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $others_all_data['others']['Grand Total'];
                                        $others_all_data['others']['Grand Total'] = $this->tab1aArray($mc_rec, $existing_array);
                                    }
                                }

                                // IF THERE IS NO OTHERS DATA IN CURRENT ITERATION THEN ADD A DEFAULT DATA
                                if($worker_check == 1 && $others_check == 0) {
                                    if(!isset($others_all_data['others']) && $month != 'year_total' && $month != 'grand_total') {
                                        $others_all_data['others'] = [
                                            'Base' => '',
                                            'MC' => __('others'),
                                            $this->months[$month] . ' ' . $year => $this->tab1aDefaultArray()
                                        ];
                                    }
                                    elseif(isset($others_all_data['others']) && isset($this->months[$month]) && !isset($others_all_data['others'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $others_all_data['others'][$this->months[$month] . ' ' . $year] = $this->tab1aDefaultArray();
                                    }
                                    elseif(isset($others_all_data['others']) && !isset($others_all_data['others'][$year . ' Total']) && $month == 'year_total') {
                                        $others_all_data['others'][$year . ' Total'] = $this->tab1aDefaultArray();
                                    }
                                    elseif(isset($others_all_data['others']) && !isset($others_all_data['others']['Grand Total']) && $month == 'grand_total') {
                                        $others_all_data['others']['Grand Total'] = $this->tab1aDefaultArray();
                                    }
                                }

                                // ALL CITIES DATA
                                if($search_type == 'area') {
                                    if(!isset($cities_all_data[$mc_rec->city_name]) && $month != 'year_total' && $month != 'grand_total') {
                                        $cities_all_data[$mc_rec->city_name] = [
                                            'Base' => $mc_rec->city_name,
                                            'MC' => __('total'),
                                            $this->months[$month] . ' ' . $year => $this->tab1aArray($mc_rec)
                                        ];
                                    }
                                    elseif(isset($cities_all_data[$mc_rec->city_name]) && isset($this->months[$month]) && !isset($cities_all_data[$mc_rec->city_name][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $cities_all_data[$mc_rec->city_name][$this->months[$month] . ' ' . $year] = $this->tab1aArray($mc_rec);
                                    }
                                    elseif(isset($cities_all_data[$mc_rec->city_name]) && !isset($cities_all_data[$mc_rec->city_name][$year . ' Total']) && $month == 'year_total') {
                                        $cities_all_data[$mc_rec->city_name][$year . ' Total'] = $this->tab1aArray($mc_rec);
                                    }
                                    elseif(isset($cities_all_data[$mc_rec->city_name]) && !isset($cities_all_data[$mc_rec->city_name]['Grand Total']) && $month == 'grand_total') {
                                        $cities_all_data[$mc_rec->city_name]['Grand Total'] = $this->tab1aArray($mc_rec);
                                    }
                                    elseif(isset($cities_all_data[$mc_rec->city_name]) && isset($this->months[$month]) && isset($cities_all_data[$mc_rec->city_name][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $cities_all_data[$mc_rec->city_name][$this->months[$month] . ' ' . $year];
                                        $cities_all_data[$mc_rec->city_name][$this->months[$month] . ' ' . $year] = $this->tab1aArray($mc_rec, $existing_array);
                                    }
                                    elseif(isset($cities_all_data[$mc_rec->city_name]) && isset($cities_all_data[$mc_rec->city_name][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $cities_all_data[$mc_rec->city_name][$year . ' Total'];
                                        $cities_all_data[$mc_rec->city_name][$year . ' Total'] = $this->tab1aArray($mc_rec, $existing_array);
                                    }
                                    elseif(isset($cities_all_data[$mc_rec->city_name]) && isset($cities_all_data[$mc_rec->city_name]['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $cities_all_data[$mc_rec->city_name]['Grand Total'];
                                        $cities_all_data[$mc_rec->city_name]['Grand Total'] = $this->tab1aArray($mc_rec, $existing_array);
                                    }
                                }

                                // ALL OFFICES DATA
                                if($search_type == 'office') {
                                    if(!isset($offices_all_data[$mc_rec->office_name]) && $month != 'year_total' && $month != 'grand_total') {
                                        $offices_all_data[$mc_rec->office_name] = [
                                            'Base' => $mc_rec->office_name,
                                            'MC' => __('total'),
                                            $this->months[$month] . ' ' . $year => $this->tab1aArray($mc_rec)
                                        ];
                                    }
                                    elseif(isset($offices_all_data[$mc_rec->office_name]) && isset($this->months[$month]) && !isset($offices_all_data[$mc_rec->office_name][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $offices_all_data[$mc_rec->office_name][$this->months[$month] . ' ' . $year] = $this->tab1aArray($mc_rec);
                                    }
                                    elseif(isset($offices_all_data[$mc_rec->office_name]) && !isset($offices_all_data[$mc_rec->office_name][$year . ' Total']) && $month == 'year_total') {
                                        $offices_all_data[$mc_rec->office_name][$year . ' Total'] = $this->tab1aArray($mc_rec);
                                    }
                                    elseif(isset($offices_all_data[$mc_rec->office_name]) && !isset($offices_all_data[$mc_rec->office_name]['Grand Total']) && $month == 'grand_total') {
                                        $offices_all_data[$mc_rec->office_name]['Grand Total'] = $this->tab1aArray($mc_rec);
                                    }
                                    elseif(isset($offices_all_data[$mc_rec->office_name]) && isset($this->months[$month]) && isset($offices_all_data[$mc_rec->office_name][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $offices_all_data[$mc_rec->office_name][$this->months[$month] . ' ' . $year];
                                        $offices_all_data[$mc_rec->office_name][$this->months[$month] . ' ' . $year] = $this->tab1aArray($mc_rec, $existing_array);
                                    }
                                    elseif(isset($offices_all_data[$mc_rec->office_name]) && isset($offices_all_data[$mc_rec->office_name][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $offices_all_data[$mc_rec->office_name][$year . ' Total'];
                                        $offices_all_data[$mc_rec->office_name][$year . ' Total'] = $this->tab1aArray($mc_rec, $existing_array);
                                    }
                                    elseif(isset($offices_all_data[$mc_rec->office_name]) && isset($offices_all_data[$mc_rec->office_name]['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $offices_all_data[$mc_rec->office_name]['Grand Total'];
                                        $offices_all_data[$mc_rec->office_name]['Grand Total'] = $this->tab1aArray($mc_rec, $existing_array);
                                    }
                                }

                                // ALL TOTAL DATA
                                if(!isset($total_column_data['column_wise_total']) && $month != 'year_total' && $month != 'grand_total') {
                                    $total_column_data['column_wise_total'] = [
                                        'Base' => __('grand_total'),
                                        'MC' => '',
                                        $this->months[$month] . ' ' . $year => $this->tab1aArray($mc_rec)
                                    ];
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($this->months[$month]) && !isset($total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                    $total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year] = $this->tab1aArray($mc_rec);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && !isset($total_column_data['column_wise_total'][$year . ' Total']) && $month == 'year_total') {
                                    $total_column_data['column_wise_total'][$year . ' Total'] = $this->tab1aArray($mc_rec);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && !isset($total_column_data['column_wise_total']['Grand Total']) && $month == 'grand_total') {
                                    $total_column_data['column_wise_total']['Grand Total'] = $this->tab1aArray($mc_rec);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($this->months[$month]) && isset($total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                    $existing_array = $total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year];
                                    $total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year] = $this->tab1aArray($mc_rec, $existing_array);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($total_column_data['column_wise_total'][$year . ' Total']) && $month == 'year_total') {
                                    $existing_array = $total_column_data['column_wise_total'][$year . ' Total'];
                                    $total_column_data['column_wise_total'][$year . ' Total'] = $this->tab1aArray($mc_rec, $existing_array);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($total_column_data['column_wise_total']['Grand Total']) && $month == 'grand_total') {
                                    $existing_array = $total_column_data['column_wise_total']['Grand Total'];
                                    $total_column_data['column_wise_total']['Grand Total'] = $this->tab1aArray($mc_rec, $existing_array);
                                }
                            }
                        }
                    }
                }
            }

            $data = [
                'search_type' => $search_type,
                'mc_data' => count($mc_all_data) ? $mc_all_data : (object)[],
                'others_data' => count($others_all_data) ? $others_all_data : (object)[],
                'area_data'   => count($cities_all_data) ? $cities_all_data : (object)[],
                'office_data' => count($offices_all_data) ? $offices_all_data : (object)[],
                'grand_total' => count($total_column_data) ? $total_column_data : (object)[],
            ];
            
	        if (count($data)) {
	            return $this->sendResponse($data, __('record_found'));
	        }

	        return $this->sendResponse([], __('record_not_found'));
	    } 
	    catch (Exception $e) {
	        $errorMessage = $e->getMessage();
            $errorFile = $e->getFile();
            $errorLine = $e->getLine();

            // Combine the error message with its location
            $errorDetails = [
                'message' => $errorMessage,
                'file' => $errorFile,
                'line' => $errorLine,
            ];

            // Assuming sendError is a method that can accept an array of error details
            return $this->sendError(__('something_went_wrong'), $errorDetails, 400);
	    }
    }
    
    // TAB 1 - DATA
    private function tab1aData($request, $dateOrYear, $getDataBy = 'month') 
    {
        try {    
            $month = $year = '';
            if($getDataBy == 'month') {
                $month = $dateOrYear->format('m');
                $year = $dateOrYear->format('Y');
            }
            elseif($getDataBy == 'year') {
                $year = $dateOrYear;
            }

            $city_id = $request->input('city_id', '');
            $office_id = $request->input('office_id', '');
            $user_ids = $request->input('user_ids', '[]');
            $user_ids = is_array($user_ids) ? $user_ids : [];
    
            $query = DB::table('users as u');
            $query->selectRaw("
                u.id,
                u.is_sales,
                u.status,
                -- user_offices.deleted_at as office_deleted,
                CONCAT(u.first_name,' ',u.last_name) as mc_name,
                offices.id as office_id,
                offices.name as office_name,
                cities.id as city_id,
                cities.name as city_name,
                COALESCE(inquiry_main_count.inquiry_count, 0) AS inquiry_count,
                COALESCE(inquiry_main_appoint_count.appointment_count, 0) AS appointment_count
            ");
            
            $query->distinct();
            $query->join('user_offices', 'u.id', '=', 'user_offices.user_id');
            $query->join('offices', 'user_offices.office_id', '=', 'offices.id');
            $query->join('cities', 'offices.city_id', '=', 'cities.id');
            $query->join('office_departments as od', 'offices.id', '=', 'od.office_id');
    
            $yearCondInquiryForSelect = !empty($year) ? "EXTRACT(YEAR FROM inq_det.inquiry_date) as year," : "";
            $monthCondInquiryForSelect = !empty($month) ? "EXTRACT(MONTH FROM inq_det.inquiry_date) as month," : "";
            $yearCondInquiryForGroupBy = !empty($year) ? "EXTRACT(YEAR FROM inq_det.inquiry_date)," : "";
            $monthCondInquiryForGroupBy = !empty($month) ? "EXTRACT(MONTH FROM inq_det.inquiry_date)," : "";
    
            // INQUIRY COUNT
            $yearConditionInquiryCount = !empty($year) ? "AND EXTRACT(YEAR FROM inq_det.inquiry_date) = '" . $year . "'" : "";
            $monthConditionInquiryCount = !empty($month) ? "AND EXTRACT(MONTH FROM inq_det.inquiry_date) = '" . $month . "' " : "";
            $query->leftJoin(
                DB::raw('(
                        SELECT 
                            inq_det.corresponding_person_id, ' .
                            $yearCondInquiryForSelect . ' ' .
                            $monthCondInquiryForSelect . '
                            COUNT(DISTINCT inq.id) AS inquiry_count,
                            inq_det.corresponding_person_office_id as office_id
                        FROM 
                            inquiries as inq
                        INNER JOIN (
                            SELECT
                                DISTINCT ON (inquiry_id) id, inquiry_id, corresponding_person_id, inquiry_date, corresponding_person_office_id
                            FROM
                                inquiries_detail
                            WHERE
                                deleted_at IS NULL
                            ORDER BY
                                inquiry_id, created_at ASC
                        ) AS inq_det ON inq.id = inq_det.inquiry_id
                        INNER JOIN 
                            office_departments as od ON
                            inq.office_departments_id = od.id
                        WHERE
                            inq.deleted_at is NULL 
                            AND od.department_id = ' . $this->department_id . ' ' .
                            $yearConditionInquiryCount . ' ' .
                            $monthConditionInquiryCount . '
                        GROUP BY
                            inq_det.corresponding_person_id, ' .
                            $yearCondInquiryForGroupBy . ' ' .
                            $monthCondInquiryForGroupBy . '
                            inq_det.corresponding_person_office_id
                    ) as inquiry_main_count'), function($join) {
                        $join->on('u.id', '=', 'inquiry_main_count.corresponding_person_id');
                        $join->where(DB::raw('offices.id'), '=', DB::raw('inquiry_main_count.office_id'));
                }
            );
    
            // APPOINTMENT COUNT
            $yearConditionAppointmentCount = !empty($year) ? "AND EXTRACT(YEAR FROM inq_det.inquiry_date) = '" . $year . "'" : "";
            $monthConditionAppointmentCount = !empty($month) ? "AND EXTRACT(MONTH FROM inq_det.inquiry_date) = '" . $month . "' " : "";
            $query->leftJoin(
                DB::raw('(
                        SELECT 
                            inq_det.corresponding_person_id, ' .
                            $yearCondInquiryForSelect . ' ' .
                            $monthCondInquiryForSelect . '
                            COUNT(DISTINCT inq.id) AS appointment_count,
                            inq_det.corresponding_person_office_id as office_id
                        FROM 
                            inquiries as inq
                        INNER JOIN (
                            SELECT
                                DISTINCT ON (inquiry_id) id, inquiry_id, corresponding_person_id, inquiry_date, corresponding_person_office_id
                            FROM
                                inquiries_detail
                            WHERE
                                deleted_at IS NULL
                            ORDER BY
                                inquiry_id, created_at ASC
                        ) AS inq_det ON inq.id = inq_det.inquiry_id
                        INNER JOIN 
                            office_departments as od ON
                            inq.office_departments_id = od.id
                        WHERE
                            inq.deleted_at is NULL AND
                            inq.interview_appoint = 1 
                            AND od.department_id = ' . $this->department_id . ' ' .
                            $yearConditionAppointmentCount . ' ' .
                            $monthConditionAppointmentCount . '
                        GROUP BY
                            inq_det.corresponding_person_id, ' .
                            $yearCondInquiryForGroupBy . ' ' .
                            $monthCondInquiryForGroupBy . '
                            inq_det.corresponding_person_office_id
                    ) as inquiry_main_appoint_count'), function($join) {
                        $join->on('inquiry_main_count.corresponding_person_id', '=', 'inquiry_main_appoint_count.corresponding_person_id');
                        if(!empty($month)) {
                            $join->where(DB::raw('inquiry_main_count.year'), '=', DB::raw('inquiry_main_appoint_count.year'));
                        }
                        if(!empty($month)) {
                            $join->where(DB::raw('inquiry_main_count.month'), '=', DB::raw('inquiry_main_appoint_count.month'));
                        }
                        $join->where(DB::raw('offices.id'), '=', DB::raw('inquiry_main_appoint_count.office_id'));
                }
            );
            
            $query->where('u.department_id', $this->department_id);
            $query->whereNotIn('u.id', $this->js_admin_ids_array);
            $query->whereNull('u.deleted_at');

            $query->where('offices.status', 1);
            $query->where('od.department_id', $this->department_id);
            $query->where('od.status', 1);
            if(!empty($city_id)) {
                $query->where('offices.city_id', $city_id);
            }
            if(!empty($office_id)) {
                $query->where('offices.id', $office_id);
            }
            if(!empty($user_ids) && count($user_ids)) {
                $query->whereIn('u.id', $user_ids);
            }
            $query->orderBy('offices.id');
            $query->orderBy('u.id');
            // echo $query->toSql(); exit;
            $data = $query->get();
            // dd($data);
    
            return $data->toArray();
        } 
        catch (Exception $e) {
            $errorMessage = $e->getMessage();
            $errorFile = $e->getFile();
            $errorLine = $e->getLine();

            // Combine the error message with its location
            $errorDetails = [
                'message' => $errorMessage,
                'file' => $errorFile,
                'line' => $errorLine,
            ];

            // Assuming sendError is a method that can accept an array of error details
            return $this->sendError(__('something_went_wrong'), $errorDetails, 400);
        }
    }

    /**
     * Current loop record - $cur_rec
     * Existing record - $ex_rec
     */
    private function tab1aArray($cur_rec, $ex_rec = [])
    {
        if(count($ex_rec)) {
            $_inquiry_count = $cur_rec->inquiry_count + $ex_rec['inquiry_count'];
            $_appointment_count = $cur_rec->appointment_count + $ex_rec['appointment_count'];

            $inquiry = [
                    'inquiry_count'     => $_inquiry_count,
                    'appointment_count' => $_appointment_count,
                    'attraction_rate'   => $this->division($_appointment_count, $_inquiry_count),
            ];
        }
        else {
            $inquiry = [
                'inquiry_count' => $cur_rec->inquiry_count,
                'appointment_count' => $cur_rec->appointment_count,
                'attraction_rate' => $this->division($cur_rec->appointment_count, $cur_rec->inquiry_count),
            ];
        }

        return $inquiry;
    }
    
    private function tab1aDefaultArray()
    {
        $inquiry = [
            'inquiry_count' => 0,
            'appointment_count' => 0,
            'attraction_rate' => '0%',
        ];

        return $inquiry;
    }

    // TAB 2
    public function tab2(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'start_date' => 'required|date_format:Y-m',
                'end_date' => 'required|date_format:Y-m',
                'is_yearly_total' => 'in:1,0',
                'is_grand_total' => 'in:1,0',
                'search_type' => 'required|in:area,office,referral',
            ]);
        
            if ($validator->fails()) {
                $errors = $validator->errors();
                $error_array = [];
                if($errors->first('start_date')) {
                    $error_array['start_date'] = [$errors->first('start_date')];
                }
                if($errors->first('end_date')) {
                    $error_array['end_date'] = [$errors->first('end_date')];
                }
                if($errors->first('is_yearly_total')) {
                    $error_array['is_yearly_total'] = [$errors->first('is_yearly_total')];
                }
                if($errors->first('is_grand_total')) {
                    $error_array['is_grand_total'] = [$errors->first('is_grand_total')];
                }
                if($errors->first('search_type')) {
                    $error_array['search_type'] = [$errors->first('search_type')];
                }
                return $this->sendError(__('something_went_wrong'), $error_array, 422);
            }

            $search_type = $request->input('search_type', '');      // Possible values area, office, referral
            $is_yearly_total = $request->input('is_yearly_total', 0);
            $is_grand_total = $request->input('is_grand_total', 0);

            // Assuming $start_date and $end_date are in 'Y-m' format, e.g., '2023-12'
            $start_date = Carbon::createFromFormat('Y-m-d', $request->start_date.'-01');
            $end_date = Carbon::createFromFormat('Y-m-d', $request->end_date.'-01')->endOfMonth(); // Ensure the end date covers the end of the month
            
            $currentDate = $start_date->copy();
            $previousYear = $start_date->year;

            // RESPONSE DATA CODE
            $response_data = [];            
            // Initial run for Function
            $response_data[$currentDate->year][$currentDate->format('m')] = $this->tab2Data($request, $currentDate, 'month');
            // Move to the next month for the loop start
            $currentDate->addMonth();
            
            while ($currentDate->lessThanOrEqualTo($end_date)) {
                // Check if the year has changed, indicating the start of a new year
                if ($currentDate->year != $previousYear && $is_yearly_total == 1) {
                    // Run Function for total of year calculation before processing the new year
                    $response_data[$previousYear]['year_total'] = $this->tab2Data($request, $previousYear, 'year');
                    $previousYear = $currentDate->year;
                }

                // Run Function for the current month
                $response_data[$currentDate->year][$currentDate->format('m')] = $this->tab2Data($request, $currentDate, 'month');

                // Move to the next month
                $currentDate->addMonth();
            }

            // After completing the loop, check if Function needs to be called for the end date's year
            if ($end_date->year == $previousYear && $is_yearly_total == 1) {
                $response_data[$end_date->year]['year_total'] = $this->tab2Data($request, $end_date->year, 'year');
                // dd($response_data[$end_date->year]['year_total']);
            }
            
            // For grand total row wise
            if($is_grand_total == 1) {
                $response_data['all_years']['grand_total'] = $this->tab2Data($request, $end_date->year, 'all_years');
            }
            // dd($response_data);

            $referral_all_data = $referral_sum_data = $offices_all_data = $areas_all_data = $total_column_data = [];
            foreach($response_data as $year => $record) {
                
                if(is_array($record)) {
                    foreach($record as $month => $ref_records) {

                        if(is_array($ref_records)) {
                            foreach($ref_records as $ref_rec) {

                                if($search_type == 'referral') {
                                    // ALL REFERRAL'S DATA
                                    if(!isset($referral_all_data[$ref_rec['referral_code'].'-'.$ref_rec['office_code']]) && $month != 'year_total' && $month != 'grand_total') {
                                        $referral_all_data[$ref_rec['referral_code'].'-'.$ref_rec['office_code']] = [
                                            'Base' => $ref_rec['office_name'],
                                            'Referral' => $ref_rec['referral_name'],
                                            $this->months[$month] . ' ' . $year => $this->tab2Array($ref_rec)
                                        ];
                                    }
                                    elseif(isset($referral_all_data[$ref_rec['referral_code'].'-'.$ref_rec['office_code']]) && $month != 'year_total' && $month != 'grand_total') {
                                        $referral_all_data[$ref_rec['referral_code'].'-'.$ref_rec['office_code']][$this->months[$month] . ' ' . $year] = $this->tab2Array($ref_rec);
                                    }
                                    elseif(isset($referral_all_data[$ref_rec['referral_code'].'-'.$ref_rec['office_code']]) && $month == 'year_total') {
                                        $referral_all_data[$ref_rec['referral_code'].'-'.$ref_rec['office_code']][$year . ' Total'] = $this->tab2Array($ref_rec);
                                    }
                                    elseif(isset($referral_all_data[$ref_rec['referral_code'].'-'.$ref_rec['office_code']]) && $month == 'grand_total') {
                                        $referral_all_data[$ref_rec['referral_code'].'-'.$ref_rec['office_code']]['Grand Total'] = $this->tab2Array($ref_rec);
                                    }
                                    
                                    // ALL REFERRAL SUM DATA
                                    if(!isset($referral_sum_data['whole - '.$ref_rec['referral_code']]) && $month != 'year_total' && $month != 'grand_total') {
                                        $referral_sum_data['whole - '.$ref_rec['referral_code']] = [
                                            'Base' => __('whole'),
                                            'Referral' => $ref_rec['referral_name'],
                                            $this->months[$month] . ' ' . $year => $this->tab2Array($ref_rec)
                                        ];
                                    }
                                    elseif(isset($referral_sum_data['whole - '.$ref_rec['referral_code']]) && isset($this->months[$month]) && !isset($referral_sum_data['whole - '.$ref_rec['referral_code']][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $referral_sum_data['whole - '.$ref_rec['referral_code']][$this->months[$month] . ' ' . $year] = $this->tab2Array($ref_rec);
                                    }
                                    elseif(isset($referral_sum_data['whole - '.$ref_rec['referral_code']]) && !isset($referral_sum_data['whole - '.$ref_rec['referral_code']][$year . ' Total']) && $month == 'year_total') {
                                        $referral_sum_data['whole - '.$ref_rec['referral_code']][$year . ' Total'] = $this->tab2Array($ref_rec);
                                    }
                                    elseif(isset($referral_sum_data['whole - '.$ref_rec['referral_code']]) && !isset($referral_sum_data['whole - '.$ref_rec['referral_code']]['Grand Total']) && $month == 'grand_total') {
                                        $referral_sum_data['whole - '.$ref_rec['referral_code']]['Grand Total'] = $this->tab2Array($ref_rec);
                                    }
                                    elseif(isset($referral_sum_data['whole - '.$ref_rec['referral_code']]) && isset($this->months[$month]) && isset($referral_sum_data['whole - '.$ref_rec['referral_code']][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $referral_sum_data['whole - '.$ref_rec['referral_code']][$this->months[$month] . ' ' . $year];                                    
                                        $referral_sum_data['whole - '.$ref_rec['referral_code']][$this->months[$month] . ' ' . $year] = $this->tab2Array($ref_rec, $existing_array);
                                    }
                                    elseif(isset($referral_sum_data['whole - '.$ref_rec['referral_code']]) && isset($referral_sum_data['whole - '.$ref_rec['referral_code']][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $referral_sum_data['whole - '.$ref_rec['referral_code']][$year . ' Total'];
                                        $referral_sum_data['whole - '.$ref_rec['referral_code']][$year . ' Total'] = $this->tab2Array($ref_rec, $existing_array);
                                    }
                                    elseif(isset($referral_sum_data['whole - '.$ref_rec['referral_code']]) && isset($referral_sum_data['whole - '.$ref_rec['referral_code']]['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $referral_sum_data['whole - '.$ref_rec['referral_code']]['Grand Total'];
                                        $referral_sum_data['whole - '.$ref_rec['referral_code']]['Grand Total'] = $this->tab2Array($ref_rec, $existing_array);
                                    }
                                }

                                // ALL AREAS SUM DATA
                                if($search_type == 'area') {
                                    if(!isset($areas_all_data['area-' . $ref_rec['city_id']]) && $month != 'year_total' && $month != 'grand_total') {
                                        $areas_all_data['area-' . $ref_rec['city_id']] = [
                                            'Base' => $ref_rec['city_name'],
                                            'Referral' => __('total'),
                                            $this->months[$month] . ' ' . $year => $this->tab2Array($ref_rec)
                                        ];
                                    }
                                    elseif(isset($areas_all_data['area-' . $ref_rec['city_id']]) && isset($this->months[$month]) && !isset($areas_all_data['area-' . $ref_rec['city_id']][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $areas_all_data['area-' . $ref_rec['city_id']][$this->months[$month] . ' ' . $year] = $this->tab2Array($ref_rec);
                                    }
                                    elseif(isset($areas_all_data['area-' . $ref_rec['city_id']]) && !isset($areas_all_data['area-' . $ref_rec['city_id']][$year . ' Total']) && $month == 'year_total') {
                                        $areas_all_data['area-' . $ref_rec['city_id']][$year . ' Total'] = $this->tab2Array($ref_rec);
                                    }
                                    elseif(isset($areas_all_data['area-' . $ref_rec['city_id']]) && !isset($areas_all_data['area-' . $ref_rec['city_id']]['Grand Total']) && $month == 'grand_total') {
                                        $areas_all_data['area-' . $ref_rec['city_id']]['Grand Total'] = $this->tab2Array($ref_rec);
                                    }
                                    elseif(isset($areas_all_data['area-' . $ref_rec['city_id']]) && isset($this->months[$month]) && isset($areas_all_data['area-' . $ref_rec['city_id']][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $areas_all_data['area-' . $ref_rec['city_id']][$this->months[$month] . ' ' . $year];
                                        $areas_all_data['area-' . $ref_rec['city_id']][$this->months[$month] . ' ' . $year] = $this->tab2Array($ref_rec, $existing_array);
                                    }
                                    elseif(isset($areas_all_data['area-' . $ref_rec['city_id']]) && isset($areas_all_data['area-' . $ref_rec['city_id']][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $areas_all_data['area-' . $ref_rec['city_id']][$year . ' Total'];
                                        $areas_all_data['area-' . $ref_rec['city_id']][$year . ' Total'] = $this->tab2Array($ref_rec, $existing_array);
                                    }
                                    elseif(isset($areas_all_data['area-' . $ref_rec['city_id']]) && isset($areas_all_data['area-' . $ref_rec['city_id']]['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $areas_all_data['area-' . $ref_rec['city_id']]['Grand Total'];
                                        $areas_all_data['area-' . $ref_rec['city_id']]['Grand Total'] = $this->tab2Array($ref_rec, $existing_array);
                                    }
                                }

                                // ALL OFFICES SUM DATA
                                if($search_type == 'office') {
                                    if(!isset($offices_all_data['office - ' . $ref_rec['office_code']]) && $month != 'year_total' && $month != 'grand_total') {
                                        $offices_all_data['office - ' . $ref_rec['office_code']] = [
                                            'Base' => $ref_rec['office_name'],
                                            'Referral' => __('total'),
                                            $this->months[$month] . ' ' . $year => $this->tab2Array($ref_rec)
                                        ];
                                    }
                                    elseif(isset($offices_all_data['office - ' . $ref_rec['office_code']]) && isset($this->months[$month]) && !isset($offices_all_data['office - ' . $ref_rec['office_code']][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $offices_all_data['office - ' . $ref_rec['office_code']][$this->months[$month] . ' ' . $year] = $this->tab2Array($ref_rec);
                                    }
                                    elseif(isset($offices_all_data['office - ' . $ref_rec['office_code']]) && !isset($offices_all_data['office - ' . $ref_rec['office_code']][$year . ' Total']) && $month == 'year_total') {
                                        $offices_all_data['office - ' . $ref_rec['office_code']][$year . ' Total'] = $this->tab2Array($ref_rec);
                                    }
                                    elseif(isset($offices_all_data['office - ' . $ref_rec['office_code']]) && !isset($offices_all_data['office - ' . $ref_rec['office_code']]['Grand Total']) && $month == 'grand_total') {
                                        $offices_all_data['office - ' . $ref_rec['office_code']]['Grand Total'] = $this->tab2Array($ref_rec);
                                    }
                                    elseif(isset($offices_all_data['office - ' . $ref_rec['office_code']]) && isset($this->months[$month]) && isset($offices_all_data['office - ' . $ref_rec['office_code']][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $offices_all_data['office - ' . $ref_rec['office_code']][$this->months[$month] . ' ' . $year];
                                        $offices_all_data['office - ' . $ref_rec['office_code']][$this->months[$month] . ' ' . $year] = $this->tab2Array($ref_rec, $existing_array);
                                    }
                                    elseif(isset($offices_all_data['office - ' . $ref_rec['office_code']]) && isset($offices_all_data['office - ' . $ref_rec['office_code']][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $offices_all_data['office - ' . $ref_rec['office_code']][$year . ' Total'];
                                        $offices_all_data['office - ' . $ref_rec['office_code']][$year . ' Total'] = $this->tab2Array($ref_rec, $existing_array);
                                    }
                                    elseif(isset($offices_all_data['office - ' . $ref_rec['office_code']]) && isset($offices_all_data['office - ' . $ref_rec['office_code']]['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $offices_all_data['office - ' . $ref_rec['office_code']]['Grand Total'];
                                        $offices_all_data['office - ' . $ref_rec['office_code']]['Grand Total'] = $this->tab2Array($ref_rec, $existing_array);
                                    }
                                }

                                // ALL TOTAL DATA
                                if(!isset($total_column_data['column_wise_total']) && $month != 'year_total' && $month != 'grand_total') {
                                    $total_column_data['column_wise_total'] = [
                                        'Base' => __('grand_total'),
                                        'Referral' => '',
                                        $this->months[$month] . ' ' . $year => $this->tab2Array($ref_rec)
                                    ];
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($this->months[$month]) && !isset($total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                    $total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year] = $this->tab2Array($ref_rec);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && !isset($total_column_data['column_wise_total'][$year . ' Total']) && $month == 'year_total') {
                                    $total_column_data['column_wise_total'][$year . ' Total'] = $this->tab2Array($ref_rec);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && !isset($total_column_data['column_wise_total']['Grand Total']) && $month == 'grand_total') {
                                    $total_column_data['column_wise_total']['Grand Total'] = $this->tab2Array($ref_rec);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($this->months[$month]) && isset($total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                    $existing_array = $total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year];                                    
                                    $total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year] = $this->tab2Array($ref_rec, $existing_array);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($total_column_data['column_wise_total'][$year . ' Total']) && $month == 'year_total') {
                                    $existing_array = $total_column_data['column_wise_total'][$year . ' Total'];
                                    $total_column_data['column_wise_total'][$year . ' Total'] = $this->tab2Array($ref_rec, $existing_array);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($total_column_data['column_wise_total']['Grand Total']) && $month == 'grand_total') {
                                    $existing_array = $total_column_data['column_wise_total']['Grand Total'];
                                    $total_column_data['column_wise_total']['Grand Total'] = $this->tab2Array($ref_rec, $existing_array);
                                }
                            }
                        }
                    }
                }
            }

            $this->removeOrderCount($referral_all_data);
            $this->removeOrderCount($referral_sum_data);
            $this->removeOrderCount($areas_all_data);
            $this->removeOrderCount($offices_all_data);
            $this->removeOrderCount($total_column_data);

            $data = [
                'search_type' => $search_type,
                'referral_data' => count($referral_all_data) ? $referral_all_data : (object)[],
                'referral_sum_data' => count($referral_sum_data) ? $referral_sum_data : (object)[],
                'area_data' => count($areas_all_data) ? $areas_all_data : (object)[],
                'office_data' => count($offices_all_data) ? $offices_all_data : (object)[],
                'grand_total' => count($total_column_data) ? $total_column_data : (object)[],
            ];
            
	        if (count($data)) {
	            return $this->sendResponse($data, __('record_found'));
	        }

	        return $this->sendResponse([], __('record_not_found'));
	    } 
	    catch (Exception $e) {
	        $errorMessage = $e->getMessage();
            $errorFile = $e->getFile();
            $errorLine = $e->getLine();

            // Combine the error message with its location
            $errorDetails = [
                'message' => $errorMessage,
                'file' => $errorFile,
                'line' => $errorLine,
            ];

            // Assuming sendError is a method that can accept an array of error details
            return $this->sendError(__('something_went_wrong'), $errorDetails, 400);
	    }
    }

    private function removeOrderCount(&$array) {
        foreach ($array as $key => &$value) {
            if (is_array($value)) {
                $this->removeOrderCount($value);
            } else {
                if ($key === 'order_count') {
                    unset($array[$key]);
                }
            }
        }
    }

    // TAB 2 - DATA
    // private function tab2Data($request, $dateOrYear, $getDataBy = 'month') 
        // {
        //     try {    
        //         $month = $year = '';
        //         if($getDataBy == 'month') {
        //             $month = $dateOrYear->format('m');
        //             $year = $dateOrYear->format('Y');
        //         }
        //         elseif($getDataBy == 'year') {
        //             $year = $dateOrYear;
        //         }

        //         $city_id = $request->input('city_id', '');
        //         $office_id = $request->input('office_id', '');
        //         $referral_ids = $request->input('referral_ids', '[]');
        //         $referral_ids = is_array($referral_ids) ? $referral_ids : [];
        
        //         $offices = DB::table('offices')
        //                     ->select('id as office_code', 'name as office_name', 'city_id', 'status');

        //         $referrals = DB::table('introduced_by')
        //                     ->select('id as referral_code', 'name as referral_name')
        //                     ->where('type', 'tax');

        //         // Assuming you want to cross join offices with referrals
        //         $query = DB::table(DB::raw("({$offices->toSql()}) as offices"))
        //                 ->selectRaw('
        //                     offices.office_code, 
        //                     offices.office_name, 
        //                     cities.id as city_id,
        //                     cities.name as city_name,
        //                     referrals.referral_code, 
        //                     referrals.referral_name,
        //                     COALESCE(interview_main_count.interview_count, 0) AS interview_count,
        //                     COALESCE(interview_main_count.estimated_amount, 0) AS estimated_amount
        //                     -- ,interview_main_count.interview_id'
        //                 )
        //                 ->mergeBindings($offices) // Important: merge bindings to include subquery parameters
        //                 ->crossJoin(DB::raw("({$referrals->toSql()}) as referrals"))
        //                 ->mergeBindings($referrals); // Important: merge bindings to include subquery parameters
        //         $query->distinct();
                
        //         $query->join('cities', 'offices.city_id', '=', 'cities.id');
        //         $query->join('office_departments as od', 'office_code', '=', 'od.office_id');
                
        //         // CALCULATING INTERVIEW COUNT AND ESTIMATED AMOUNT
        //         $yearCondInterviewForSelect = !empty($year) ? "EXTRACT(YEAR FROM int_det2.interview_date) as year," : "";
        //         $monthCondInterviewForSelect = !empty($month) ? "EXTRACT(MONTH FROM int_det2.interview_date) as month," : "";

        //         $yearConditionInterviewWhere = !empty($year) ? "AND EXTRACT(YEAR FROM int_det2.interview_date) = '" . $year . "'" : "";
        //         $monthConditionInterviewWhere = !empty($month) ? "AND EXTRACT(MONTH FROM int_det2.interview_date) = '" . $month . "' " : "";

        //         $yearCondInterviewForGroupBy = !empty($year) ? "EXTRACT(YEAR FROM int_det2.interview_date)," : "";
        //         $monthCondInterviewForGroupBy = !empty($month) ? "EXTRACT(MONTH FROM int_det2.interview_date)," : "";

        //         $query->leftJoin(
        //                 DB::raw('(
        //                     SELECT 
        //                         od.office_id as approach_office,
        //                         int.introduced_by_id as client_referral, ' .
        //                         $yearCondInterviewForSelect . ' ' .
        //                         $monthCondInterviewForSelect . '
        //                         -- int_det2.interview_id,
        //                         COUNT(int_det2.id) as interview_count,
        //                         SUM(int_det.estimated_amount) as estimated_amount
        //                     FROM
        //                         interviews AS int
        //                     LEFT JOIN LATERAL (
        //                         SELECT
        //                             main.*
        //                         FROM
        //                             interviews_detail AS main
        //                         WHERE
        //                             deleted_at IS NULL
        //                             AND id = (
        //                             SELECT
        //                                 MAX(id)
        //                             FROM
        //                                 interviews_detail AS sub
        //                             WHERE
        //                                 sub.deleted_at IS NULL
        //                                 AND sub.office_id = od.office_id
        //                                 AND main.interview_id = sub.interview_id)
        //                     ) AS int_det on int.id = int_det.interview_id
        //                     LEFT OUTER JOIN customers AS client on
        //                         int.customer_id = client.id
                        
        //                     LEFT OUTER JOIN (
        //                         SELECT
        //                             interview_id,
        //                             interviewer1,
        //                             MAX(id)
        //                         FROM
        //                             interviews_detail
        //                         group by
        //                             interviewer1,interview_id
        //                     ) AS member on
        //                         int.id = member.interview_id
        //                     LEFT OUTER JOIN users on
        //                         member.interviewer1 = users.id
                            
        //                     LEFT JOIN LATERAL (
        //                         SELECT
        //                             main.*
        //                         FROM
        //                             interviews_detail AS main
        //                         WHERE
        //                             deleted_at IS NULL
        //                             AND id = (
        //                             SELECT
        //                                 MIN(id)
        //                             FROM
        //                                 interviews_detail AS sub
        //                             WHERE
        //                                 sub.deleted_at IS NULL
        //                                 AND sub.office_id = od.office_id
        //                                 AND main.interview_id = sub.interview_id)
        //                     ) AS int_det2 on
        //                         int.id = int_det2.interview_id
        //                     INNER JOIN 
        //                         office_departments AS od ON
        //                         int.office_departments_id = od.id AND od.department_id = ' . $this->department_id . ' 
        //                     INNER JOIN 
        //                         offices ON
        //                         od.office_id = offices.id
        //                     WHERE
        //                         int.deleted_at IS NULL AND
        //                         int.project_category = 1 AND
        //                         int.result_type != 1 ' .
        //                         $yearConditionInterviewWhere . ' ' .
        //                         $monthConditionInterviewWhere . '
        //                     GROUP BY
        //                         od.office_id,
        //                         -- int_det2.interview_id,
        //                         int.introduced_by_id,' .
        //                         $yearCondInterviewForGroupBy . ' ' .
        //                         $monthCondInterviewForGroupBy . '
        //                         -- int_det2.interview_date
        //                 ) AS interview_main_count'), function($join) {
        //                     $join->on('offices.office_code', '=', 'interview_main_count.approach_office');
        //                     $join->on('referrals.referral_code', '=', 'interview_main_count.client_referral');
        //                 }
        //         );
                
        //         $query->where('offices.status', 1);
        //         $query->where('od.status', 1);
        //         $query->where('od.department_id', $this->department_id);
        //         if(!empty($city_id)) {
        //             $query->where('offices.city_id', $city_id);
        //         }
        //         if(!empty($office_id)) {
        //             $query->where('offices.office_code', $office_id);
        //         }
        //         if(!empty($referral_ids) && count($referral_ids)) {
        //             $query->whereIn('referrals.referral_code', $referral_ids);
        //         }

        //         // echo $query->toSql();exit;
        //         $data1 = $query->get();

        //         $ist_batch = [];
        //         foreach($data1 as $d) {
        //             if(!isset($ist_batch[$d->office_code.'-'.$d->referral_code])) {
        //                 $ist_batch[$d->office_code.'-'.$d->referral_code] = [
        //                     'office_code' => $d->office_code,
        //                     'office_name' => $d->office_name,
        //                     'city_id' => $d->city_id,
        //                     'city_name' => $d->city_name,
        //                     'referral_code' => $d->referral_code,
        //                     'referral_name' => $d->referral_name,
        //                     'interview_count' => $d->interview_count,
        //                     'estimated_amount' => $d->estimated_amount,
        //                 ];
        //             }
        //             elseif(isset($ist_batch[$d->office_code.'-'.$d->referral_code])) {
        //                 $existing_data = $ist_batch[$d->office_code.'-'.$d->referral_code];

        //                 $ist_batch[$d->office_code.'-'.$d->referral_code]['interview_count'] = $d->interview_count + $existing_data['interview_count'];
        //                 $ist_batch[$d->office_code.'-'.$d->referral_code]['estimated_amount'] = (int)($d->estimated_amount + $existing_data['estimated_amount']);
        //             }
        //         }
        //         // dd($ist_batch);
        //         /* ******************************************************************************************** */

        //         $query = DB::table(DB::raw("({$offices->toSql()}) as offices"))
        //                 ->selectRaw('
        //                     offices.office_code, 
        //                     offices.office_name, 
        //                     cities.id as city_id,
        //                     cities.name as city_name,
        //                     referrals.referral_code, 
        //                     referrals.referral_name,
        //                     COALESCE(contract_main_count.contract_count, 0) AS contract_count,
        //                     COALESCE(contract_main_count.order_amount, 0) AS order_amount,
        //                     contract_main_count.interview_id'
        //                 )
        //                 ->mergeBindings($offices) // Important: merge bindings to include subquery parameters
        //                 ->crossJoin(DB::raw("({$referrals->toSql()}) as referrals"))
        //                 ->mergeBindings($referrals); // Important: merge bindings to include subquery parameters
        //         $query->distinct();

        //         $query->join('cities', 'offices.city_id', '=', 'cities.id');
        //         $query->join('office_departments as od', 'office_code', '=', 'od.office_id');

        //         // CALCULATING CONTRACT COUNT AND ORDER AMOUNT
        //         $yearCondInterviewForSelect = !empty($year) ? "EXTRACT(YEAR FROM int_det.interview_date) as year," : "";
        //         $monthCondInterviewForSelect = !empty($month) ? "EXTRACT(MONTH FROM int_det.interview_date) as month," : "";

        //         $yearConditionInterviewWhere = !empty($year) ? "AND EXTRACT(YEAR FROM int_det.interview_date) = '" . $year . "'" : "";
        //         $monthConditionInterviewWhere = !empty($month) ? "AND EXTRACT(MONTH FROM int_det.interview_date) = '" . $month . "' " : "";

        //         $yearCondInterviewForGroupBy = !empty($year) ? "EXTRACT(YEAR FROM int_det.interview_date)," : "";
        //         $monthCondInterviewForGroupBy = !empty($month) ? "EXTRACT(MONTH FROM int_det.interview_date)," : "";
        //         $query->leftJoin(
        //             DB::raw('(
        //                 SELECT 
        //                     od.office_id as approach_office,
        //                     int_det.interview_id,
        //                     int.introduced_by_id as client_referral, ' .
        //                     $yearCondInterviewForSelect . ' ' .
        //                     $monthCondInterviewForSelect . '
        //                     COUNT(int_det.id) as contract_count,
        //                     SUM(psoz.interview_order_amount) as order_amount
        //                 FROM
        //                     interviews as int
        //                 LEFT JOIN LATERAL (
        //                     SELECT
        //                         main.*
        //                     FROM
        //                         interviews_detail as main
        //                     WHERE
        //                         deleted_at IS NULL
        //                         AND id = (
        //                             SELECT
        //                                 MIN(id)
        //                             FROM
        //                                 interviews_detail as sub
        //                             WHERE
        //                                 sub.deleted_at IS NULL
        //                                 AND sub.office_id = od.office_id
        //                                 AND main.interview_id = sub.interview_id
        //                         )
        //                 ) as int_det on int.id = int_det.interview_id
        //                 LEFT OUTER JOIN customers as client on
        //                     int.customer_id = client.id
                        
        //                 LEFT OUTER JOIN (
        //                     SELECT
        //                         interview_id,
        //                         interviewer1,
        //                         MAX(id)
        //                     FROM
        //                         interviews_detail
        //                     group by
        //                         interviewer1,interview_id
        //                 ) as member on
        //                     int.id = member.interview_id
        //                 LEFT OUTER JOIN users on
        //                     member.interviewer1 = users.id
        //                 LEFT JOIN project_sozoku as psoz ON
        //                         int.id = psoz.interview_id
        //                 INNER JOIN 
        //                     office_departments as od ON
        //                     int.office_departments_id = od.id AND od.department_id = ' . $this->department_id . '
        //                 INNER JOIN 
        //                     offices ON
        //                     od.office_id = offices.id
        //                 WHERE
        //                     int.deleted_at IS NULL AND
        //                     int.project_category = 1 AND
        //                     int.result_type = 2 ' .
        //                     $yearConditionInterviewWhere . ' ' .
        //                     $monthConditionInterviewWhere . '
        //                 GROUP BY
        //                     od.office_id,
        //                     int_det.interview_id,
        //                     int.introduced_by_id,' .
        //                     $yearCondInterviewForGroupBy . ' ' .
        //                     $monthCondInterviewForGroupBy . '
        //                     int_det.interview_date
        //             ) as contract_main_count'), function($join) {
        //                 $join->on('offices.office_code', '=', 'contract_main_count.approach_office');
        //                 $join->on('referrals.referral_code', '=', 'contract_main_count.client_referral');
        //             }
        //         );

        //         $query->where('offices.status', 1);
        //         $query->where('od.status', 1);
        //         $query->where('od.department_id', $this->department_id);
        //         if(!empty($city_id)) {
        //             $query->where('offices.city_id', $city_id);
        //         }
        //         if(!empty($office_id)) {
        //             $query->where('offices.office_code', $office_id);
        //         }
        //         if(!empty($referral_ids) && count($referral_ids)) {
        //             $query->whereIn('referrals.referral_code', $referral_ids);
        //         }

        //         // echo $query->toSql();exit;
        //         $data2 = $query->get();

        //         $second_batch = [];
        //         foreach($data2 as $d) {
        //             if(!isset($second_batch[$d->office_code.'-'.$d->referral_code])) {
        //                 $second_batch[$d->office_code.'-'.$d->referral_code] = [
        //                     'office_code' => $d->office_code,
        //                     'office_name' => $d->office_name,
        //                     'city_id' => $d->city_id,
        //                     'city_name' => $d->city_name,
        //                     'referral_code' => $d->referral_code,
        //                     'referral_name' => $d->referral_name,
        //                     'contract_count' => $d->contract_count,
        //                     'order_amount' => $d->order_amount,
        //                 ];
        //             }
        //             elseif(isset($second_batch[$d->office_code.'-'.$d->referral_code])) {
        //                 $existing_data = $second_batch[$d->office_code.'-'.$d->referral_code];

        //                 $second_batch[$d->office_code.'-'.$d->referral_code]['contract_count'] = $d->contract_count + $existing_data['contract_count'];
        //                 $second_batch[$d->office_code.'-'.$d->referral_code]['order_amount'] = (int)($d->order_amount + $existing_data['order_amount']);
        //             }
        //         }
        //         // dd($ist_batch, $second_batch);

        //         $final_batch = [];
        //         $i = 0;
        //         foreach($ist_batch as $k => $data) {
        //             $final_batch[$i] = $data;
        //             if(isset($second_batch[$k])) {
        //                 $final_batch[$i]['contract_count'] = $second_batch[$k]['contract_count'];
        //                 $final_batch[$i]['contract_rate'] = $this->division($second_batch[$k]['contract_count'], $data['interview_count']);
        //                 $final_batch[$i]['order_amount'] = $second_batch[$k]['order_amount'];
        //             }
        //             else {
        //                 $final_batch[$i]['contract_count'] = 0;
        //                 $final_batch[$i]['contract_rate'] = 0;
        //                 $final_batch[$i]['order_amount'] = 0;
        //             }
        //             $i++;
        //         }

        //         // dd($final_batch);
        //         return $final_batch;
        //     } 
        //     catch (Exception $e) {
        //         $errorMessage = $e->getMessage();
        //         $errorFile = $e->getFile();
        //         $errorLine = $e->getLine();

        //         // Combine the error message with its location
        //         $errorDetails = [
        //             'message' => $errorMessage,
        //             'file' => $errorFile,
        //             'line' => $errorLine,
        //         ];

        //         // Assuming sendError is a method that can accept an array of error details
        //         return $this->sendError(__('something_went_wrong'), $errorDetails, 400);
        //     }
    // }

    // private function tab2Data($request, $dateOrYear, $getDataBy = 'month') 
        // {
        //     try {    
        //         $month = $year = '';
        //         if ($getDataBy == 'month') {
        //             $month = $dateOrYear->format('m');
        //             $year = $dateOrYear->format('Y');
        //         } elseif ($getDataBy == 'year') {
        //             $year = $dateOrYear;
        //         }

        //         $city_id = $request->input('city_id', '');
        //         $office_id = $request->input('office_id', '');
        //         $referral_ids = $request->input('referral_ids', '[]');
        //         $referral_ids = is_array($referral_ids) ? $referral_ids : [];

        //         $offices = DB::table('offices')
        //             ->select('id as office_code', 'name as office_name', 'city_id', 'status');

        //         $referrals = DB::table('introduced_by')
        //             ->select('id as referral_code', 'name as referral_name')
        //             ->where('type', 'tax');

        //         // Assuming you want to cross join offices with referrals
        //         $query = DB::table(DB::raw("({$offices->toSql()}) as offices"))
        //             ->selectRaw('
        //                 offices.office_code, 
        //                 offices.office_name, 
        //                 cities.id as city_id,
        //                 cities.name as city_name,
        //                 referrals.referral_code, 
        //                 referrals.referral_name,
        //                 COALESCE(interview_main_count.interview_count, 0) AS interview_count,
        //                 COALESCE(interview_main_count.estimated_amount, 0) AS estimated_amount
        //             ')
        //             ->mergeBindings($offices) // Important: merge bindings to include subquery parameters
        //             ->crossJoin(DB::raw("({$referrals->toSql()}) as referrals"))
        //             ->mergeBindings($referrals); // Important: merge bindings to include subquery parameters

        //         $query->distinct()
        //             ->join('cities', 'offices.city_id', '=', 'cities.id')
        //             ->join('office_departments as od', 'offices.office_code', '=', 'od.office_id');

        //         // CALCULATING INTERVIEW COUNT AND ESTIMATED AMOUNT
        //         $yearCondInterviewForSelect = !empty($year) ? "EXTRACT(YEAR FROM int_det2.interview_date) as year," : "";
        //         $monthCondInterviewForSelect = !empty($month) ? "EXTRACT(MONTH FROM int_det2.interview_date) as month," : "";

        //         $yearConditionInterviewWhere = !empty($year) ? "AND EXTRACT(YEAR FROM int_det2.interview_date) = '" . $year . "'" : "";
        //         $monthConditionInterviewWhere = !empty($month) ? "AND EXTRACT(MONTH FROM int_det2.interview_date) = '" . $month . "' " : "";

        //         $yearCondInterviewForGroupBy = !empty($year) ? "EXTRACT(YEAR FROM int_det2.interview_date)," : "";
        //         $monthCondInterviewForGroupBy = !empty($month) ? "EXTRACT(MONTH FROM int_det2.interview_date)" : "";

        //         $interviewMainCountQuery = DB::table('interviews as int')
        //             ->selectRaw("
        //                 od.office_id as approach_office,
        //                 int.introduced_by_id as client_referral,
        //                 {$yearCondInterviewForSelect}
        //                 {$monthCondInterviewForSelect}
        //                 COUNT(int_det2.id) as interview_count,
        //                 SUM(int_det.estimated_amount) as estimated_amount
        //             ")
        //             ->leftJoin(DB::raw('LATERAL (
        //                 SELECT main.*
        //                 FROM interviews_detail as main
        //                 WHERE deleted_at IS NULL
        //                 AND id = (
        //                     SELECT MAX(id)
        //                     FROM interviews_detail as sub
        //                     WHERE sub.deleted_at IS NULL
        //                     AND sub.office_id = od.office_id
        //                     AND main.interview_id = sub.interview_id
        //                 )
        //                 AND int.id = main.interview_id
        //             ) int_det'), 'int.id', '=', 'int_det.interview_id')
        //             ->leftJoin('customers as client', 'int.customer_id', '=', 'client.id')
        //             ->leftJoin(
        //                 DB::raw('(
        //                     SELECT
        //                         interview_id,
        //                         interviewer1,
        //                         MAX(id)
        //                     FROM
        //                         interviews_detail
        //                     group by
        //                         interviewer1, interview_id
        //                 ) AS member'), function($join) {
        //                     $join->on('int.id', '=', 'member.interview_id');
        //                 }
        //             )
        //             ->leftJoin('users', 'member.interviewer1', '=', 'users.id')
        //             ->leftJoin(DB::raw('LATERAL (
        //                 SELECT main.*
        //                 FROM interviews_detail as main
        //                 WHERE deleted_at IS NULL
        //                 AND id = (
        //                     SELECT MIN(id)
        //                     FROM interviews_detail as sub
        //                     WHERE sub.deleted_at IS NULL
        //                     AND sub.office_id = od.office_id
        //                     AND main.interview_id = sub.interview_id
        //                 )
        //                 AND int.id = main.interview_id
        //             ) int_det2'), 'int.id', '=', 'int_det2.interview_id')
        //             ->join('office_departments as od', function ($join) {
        //                 $join->on('int.office_departments_id', '=', 'od.id')
        //                     ->where('od.department_id', '=', $this->department_id);
        //             })
        //             ->join('offices', 'offices.id', '=', 'od.office_id')
        //             ->whereNull('int.deleted_at')
        //             ->where('int.project_category', 1)
        //             ->where('int.result_type', '!=', 1)
        //             ->whereRaw("1=1 {$yearConditionInterviewWhere} {$monthConditionInterviewWhere}")
        //             ->groupByRaw("
        //                 od.office_id,
        //                 int.introduced_by_id,
        //                 {$yearCondInterviewForGroupBy}
        //                 {$monthCondInterviewForGroupBy}
        //             "); // Removed the trailing comma

        //         $query->leftJoin(DB::raw("LATERAL ({$interviewMainCountQuery->toSql()}) as interview_main_count"), function ($join) {
        //             $join->on('offices.office_code', '=', 'interview_main_count.approach_office')
        //                 ->on('referrals.referral_code', '=', 'interview_main_count.client_referral');
        //         })
        //         ->mergeBindings($interviewMainCountQuery); // Important: merge bindings to include subquery parameters

        //         $query->where('offices.status', 1)
        //             ->where('od.status', 1)
        //             ->where('od.department_id', $this->department_id);

        //         if (!empty($city_id)) {
        //             $query->where('offices.city_id', $city_id);
        //         }

        //         if (!empty($office_id)) {
        //             $query->where('offices.office_code', $office_id);
        //         }

        //         if (!empty($referral_ids) && count($referral_ids)) {
        //             $query->whereIn('referrals.referral_code', $referral_ids);
        //         }

        //         // Execute the query
        //         $data1 = $query->get();


        //         $ist_batch = [];
        //         foreach($data1 as $d) {
        //             if(!isset($ist_batch[$d->office_code.'-'.$d->referral_code])) {
        //                 $ist_batch[$d->office_code.'-'.$d->referral_code] = [
        //                     'office_code' => $d->office_code,
        //                     'office_name' => $d->office_name,
        //                     'city_id' => $d->city_id,
        //                     'city_name' => $d->city_name,
        //                     'referral_code' => $d->referral_code,
        //                     'referral_name' => $d->referral_name,
        //                     'interview_count' => $d->interview_count,
        //                     'estimated_amount' => $d->estimated_amount,
        //                 ];
        //             }
        //             elseif(isset($ist_batch[$d->office_code.'-'.$d->referral_code])) {
        //                 $existing_data = $ist_batch[$d->office_code.'-'.$d->referral_code];

        //                 $ist_batch[$d->office_code.'-'.$d->referral_code]['interview_count'] = $d->interview_count + $existing_data['interview_count'];
        //                 $ist_batch[$d->office_code.'-'.$d->referral_code]['estimated_amount'] = (int)($d->estimated_amount + $existing_data['estimated_amount']);
        //             }
        //         }
        //         // dd($ist_batch);
        //         /* ******************************************************************************************** */

        //         $query = DB::table(DB::raw("({$offices->toSql()}) as offices"))
        //                 ->selectRaw('
        //                     offices.office_code, 
        //                     offices.office_name, 
        //                     cities.id as city_id,
        //                     cities.name as city_name,
        //                     referrals.referral_code, 
        //                     referrals.referral_name,
        //                     COALESCE(contract_main_count.contract_count, 0) AS contract_count,
        //                     COALESCE(contract_main_count.order_amount, 0) AS order_amount'
        //                 )
        //                 ->mergeBindings($offices) // Important: merge bindings to include subquery parameters
        //                 ->crossJoin(DB::raw("({$referrals->toSql()}) as referrals"))
        //                 ->mergeBindings($referrals); // Important: merge bindings to include subquery parameters
        //         $query->distinct();

        //         $query->join('cities', 'offices.city_id', '=', 'cities.id');
        //         $query->join('office_departments as od', 'office_code', '=', 'od.office_id');

        //         // CALCULATING CONTRACT COUNT AND ORDER AMOUNT
        //         $yearCondInterviewForSelect = !empty($year) ? "EXTRACT(YEAR FROM int_det.interview_date) as year," : "";
        //         $monthCondInterviewForSelect = !empty($month) ? "EXTRACT(MONTH FROM int_det.interview_date) as month," : "";

        //         $yearConditionInterviewWhere = !empty($year) ? "AND EXTRACT(YEAR FROM int_det.interview_date) = '" . $year . "'" : "";
        //         $monthConditionInterviewWhere = !empty($month) ? "AND EXTRACT(MONTH FROM int_det.interview_date) = '" . $month . "' " : "";

        //         $yearCondInterviewForGroupBy = !empty($year) ? "EXTRACT(YEAR FROM int_det.interview_date)," : "";
        //         $monthCondInterviewForGroupBy = !empty($month) ? "EXTRACT(MONTH FROM int_det.interview_date)" : "";
                
        //         $contractMainCountQuery = DB::table('interviews as int')
        //         ->selectRaw("
        //             od.office_id as approach_office,
        //             int.introduced_by_id as client_referral,
        //             {$yearCondInterviewForSelect}
        //             {$monthCondInterviewForSelect}
        //             COUNT(int_det.id) as contract_count,
        //             SUM(psoz.interview_order_amount) as order_amount
        //         ")
        //         ->leftJoin(DB::raw('LATERAL (
        //             SELECT main.*
        //             FROM interviews_detail as main
        //             WHERE deleted_at IS NULL
        //             AND id = (
        //                 SELECT MIN(id)
        //                 FROM interviews_detail as sub
        //                 WHERE sub.deleted_at IS NULL
        //                 AND sub.office_id = od.office_id
        //                 AND main.interview_id = sub.interview_id
        //             )
        //             AND int.id = main.interview_id
        //         ) int_det'), 'int.id', '=', 'int_det.interview_id')
        //         ->leftJoin('customers as client', 'int.customer_id', '=', 'client.id')
        //         ->leftJoin(
        //             DB::raw('(
        //                 SELECT
        //                     interview_id,
        //                     interviewer1,
        //                     MAX(id)
        //                 FROM
        //                     interviews_detail
        //                 group by
        //                     interviewer1,interview_id
        //             ) AS member'), function($join) {
        //                 $join->on('int.id', '=', 'member.interview_id');
        //             }
        //         )
        //         ->leftJoin('users', 'member.interviewer1', '=', 'users.id')
        //         ->leftJoin('project_sozoku psoz', 'int.id', '=', 'psoz.interview_id')
        //         ->join('office_departments as od', function ($join) {
        //             $join->on('int.office_departments_id', '=', 'od.id')
        //                 ->where('od.department_id', '=', $this->department_id);
        //         })
        //         ->join('offices', 'offices.id', '=', 'od.office_id')
        //         ->whereNull('int.deleted_at')
        //         ->where('int.project_category', 1)
        //         ->where('int.result_type', '=', 2)
        //         ->whereRaw("{$yearConditionInterviewWhere}")
        //         ->whereRaw("{$monthConditionInterviewWhere}")
        //         ->groupByRaw("
        //             int.office_id,
        //             int.introduced_by_id,
        //             {$yearCondInterviewForGroupBy}
        //             {$monthCondInterviewForGroupBy}
        //         ");

        //         $query->leftJoin(DB::raw("({$contractMainCountQuery->toSql()}) as contract_main_count"), function ($join) {
        //             $join->on('offices.office_code', '=', 'contract_main_count.approach_office')
        //                  ->on('referrals.referral_code', '=', 'contract_main_count.client_referral');
        //         })
        //         ->mergeBindings($contractMainCountQuery); // Important: merge bindings to include subquery parameters

        //         $query->where('offices.status', 1);
        //         $query->where('od.status', 1);
        //         $query->where('od.department_id', $this->department_id);
        //         if(!empty($city_id)) {
        //             $query->where('offices.city_id', $city_id);
        //         }
        //         if(!empty($office_id)) {
        //             $query->where('offices.office_code', $office_id);
        //         }
        //         if(!empty($referral_ids) && count($referral_ids)) {
        //             $query->whereIn('referrals.referral_code', $referral_ids);
        //         }

        //         // echo $query->toSql();exit;
        //         $data2 = $query->get();

        //         $second_batch = [];
        //         foreach($data2 as $d) {
        //             if(!isset($second_batch[$d->office_code.'-'.$d->referral_code])) {
        //                 $second_batch[$d->office_code.'-'.$d->referral_code] = [
        //                     'office_code' => $d->office_code,
        //                     'office_name' => $d->office_name,
        //                     'city_id' => $d->city_id,
        //                     'city_name' => $d->city_name,
        //                     'referral_code' => $d->referral_code,
        //                     'referral_name' => $d->referral_name,
        //                     'contract_count' => $d->contract_count,
        //                     'order_amount' => $d->order_amount,
        //                 ];
        //             }
        //             elseif(isset($second_batch[$d->office_code.'-'.$d->referral_code])) {
        //                 $existing_data = $second_batch[$d->office_code.'-'.$d->referral_code];

        //                 $second_batch[$d->office_code.'-'.$d->referral_code]['contract_count'] = $d->contract_count + $existing_data['contract_count'];
        //                 $second_batch[$d->office_code.'-'.$d->referral_code]['order_amount'] = (int)($d->order_amount + $existing_data['order_amount']);
        //             }
        //         }
        //         // dd($ist_batch, $second_batch);

        //         $final_batch = [];
        //         $i = 0;
        //         foreach($ist_batch as $k => $data) {
        //             $final_batch[$i] = $data;
        //             if(isset($second_batch[$k])) {
        //                 $final_batch[$i]['contract_count'] = $second_batch[$k]['contract_count'];
        //                 $final_batch[$i]['contract_rate'] = $this->division($second_batch[$k]['contract_count'], $data['interview_count']);
        //                 $final_batch[$i]['order_amount'] = $second_batch[$k]['order_amount'];
        //             }
        //             else {
        //                 $final_batch[$i]['contract_count'] = 0;
        //                 $final_batch[$i]['contract_rate'] = 0;
        //                 $final_batch[$i]['order_amount'] = 0;
        //             }
        //             $i++;
        //         }

        //         // dd($final_batch);
        //         return $final_batch;
        //     } 
        //     catch (Exception $e) {
        //         $errorMessage = $e->getMessage();
        //         $errorFile = $e->getFile();
        //         $errorLine = $e->getLine();

        //         // Combine the error message with its location
        //         $errorDetails = [
        //             'message' => $errorMessage,
        //             'file' => $errorFile,
        //             'line' => $errorLine,
        //         ];

        //         // Assuming sendError is a method that can accept an array of error details
        //         return $this->sendError(__('something_went_wrong'), $errorDetails, 400);
        //     }
    // }

    // private function tab2Data($request, $dateOrYear, $getDataBy = 'month') 
        // {
        //     try {    
        //         $month = $year = '';
        //         if($getDataBy == 'month') {
        //             $month = $dateOrYear->format('m');
        //             $year = $dateOrYear->format('Y');
        //         }
        //         elseif($getDataBy == 'year') {
        //             $year = $dateOrYear;
        //         }

        //         $city_id = $request->input('city_id', '');
        //         $office_id = $request->input('office_id', '');
        //         $referral_ids = $request->input('referral_ids', '[]');
        //         $referral_ids = is_array($referral_ids) ? $referral_ids : [];
        
        //         $offices = DB::table('offices')
        //                     ->select('id as office_code', 'name as office_name', 'city_id', 'status');

        //         $referrals = DB::table('introduced_by')
        //                     ->select('id as referral_code', 'name as referral_name')
        //                     ->where('type', 'tax');

        //         // Assuming you want to cross join offices with referrals
        //         $query = DB::table(DB::raw("({$offices->toSql()}) as offices"))
        //                 ->selectRaw('
        //                     offices.office_code, 
        //                     offices.office_name, 
        //                     cities.id as city_id,
        //                     cities.name as city_name,
        //                     referrals.referral_code, 
        //                     referrals.referral_name,
        //                     COALESCE(interview_main_count.interview_count, 0) AS interview_count,
        //                     COALESCE(interview_main_count.estimated_amount, 0) AS estimated_amount'
        //                 )
        //                 ->mergeBindings($offices) // Important: merge bindings to include subquery parameters
        //                 ->crossJoin(DB::raw("({$referrals->toSql()}) as referrals"))
        //                 ->mergeBindings($referrals); // Important: merge bindings to include subquery parameters
        //         $query->distinct();
                
        //         $query->join('cities', 'offices.city_id', '=', 'cities.id');
        //         $query->join('office_departments as od', 'office_code', '=', 'od.office_id');
                
        //         // CALCULATING INTERVIEW COUNT AND ESTIMATED AMOUNT
        //         $yearCondInterviewForSelect = !empty($year) ? "EXTRACT(YEAR FROM int_det2.interview_date) as year," : "";
        //         $monthCondInterviewForSelect = !empty($month) ? "EXTRACT(MONTH FROM int_det2.interview_date) as month," : "";

        //         $yearConditionInterviewWhere = !empty($year) ? "AND EXTRACT(YEAR FROM int_det2.interview_date) = '" . $year . "'" : "";
        //         $monthConditionInterviewWhere = !empty($month) ? "AND EXTRACT(MONTH FROM int_det2.interview_date) = '" . $month . "' " : "";

        //         $yearCondInterviewForGroupBy = !empty($year) ? "EXTRACT(YEAR FROM int_det2.interview_date)," : "";
        //         $monthCondInterviewForGroupBy = !empty($month) ? "EXTRACT(MONTH FROM int_det2.interview_date)" : "";

        //         $query->leftJoin(
        //                 DB::raw('LATERAL (
        //                     SELECT 
        //                         od.office_id as approach_office,
        //                         int.introduced_by_id as client_referral, ' .
        //                         $yearCondInterviewForSelect . ' ' .
        //                         $monthCondInterviewForSelect . '
        //                         COUNT(int_det2.id) as interview_count,
        //                         SUM(int_det.estimated_amount) as estimated_amount
        //                     FROM
        //                         interviews AS int
        //                     LEFT JOIN LATERAL (
        //                         SELECT
        //                             main.*
        //                         FROM
        //                             interviews_detail AS main
        //                         WHERE
        //                             deleted_at IS NULL
        //                             AND id = (
        //                                 SELECT
        //                                     MAX(id)
        //                                 FROM
        //                                     interviews_detail AS sub
        //                                 WHERE
        //                                     sub.deleted_at IS NULL
        //                                     AND sub.office_id = od.office_id
        //                                     AND main.interview_id = sub.interview_id
        //                             )
        //                             AND int.id = main.interview_id
        //                     ) AS int_det on true

        //                     -- LEFT OUTER JOIN customers AS client on
        //                     --     int.customer_id = client.id
                        
        //                     LEFT OUTER JOIN (
        //                         SELECT
        //                             interview_id,
        //                             interviewer1,
        //                             MAX(id)
        //                         FROM
        //                             interviews_detail
        //                         group by
        //                             interviewer1,interview_id
        //                     ) AS member on
        //                         int.id = member.interview_id
        //                     LEFT OUTER JOIN users on
        //                         member.interviewer1 = users.id
                            
        //                     LEFT JOIN LATERAL (
        //                         SELECT
        //                             main.*
        //                         FROM
        //                             interviews_detail AS main
        //                         WHERE
        //                             deleted_at IS NULL
        //                             AND id = (
        //                                 SELECT
        //                                     MIN(id)
        //                                 FROM
        //                                     interviews_detail AS sub
        //                                 WHERE
        //                                     sub.deleted_at IS NULL
        //                                     AND sub.office_id = od.office_id
        //                                     AND main.interview_id = sub.interview_id
        //                             )
        //                             AND int.id = main.interview_id
        //                     ) AS int_det2 on true

        //                     -- INNER JOIN 
        //                     --     office_departments AS od ON
        //                     --     int.office_departments_id = od.id AND od.department_id = ' . $this->department_id . ' 
        //                     -- INNER JOIN 
        //                     --     offices ON
        //                     --     od.office_id = offices.id

        //                     WHERE
        //                         int.office_departments_id = od.id 
        //                         AND od.department_id = ' . $this->department_id . ' 
        //                         AND int.deleted_at IS NULL 
        //                         AND int.project_category = 1 
        //                         AND int.result_type != 1 ' .
        //                         $yearConditionInterviewWhere . ' ' .
        //                         $monthConditionInterviewWhere . '
        //                     GROUP BY
        //                         od.office_id,
        //                         int.introduced_by_id,' .
        //                         $yearCondInterviewForGroupBy . ' ' .
        //                         $monthCondInterviewForGroupBy . '
        //                 ) AS interview_main_count'), function($join) {
        //                     $join->on('offices.office_code', '=', 'interview_main_count.approach_office');
        //                     $join->on('referrals.referral_code', '=', 'interview_main_count.client_referral');
        //                 }
        //         );
                
        //         $query->where('offices.status', 1);
        //         $query->where('od.status', 1);
        //         $query->where('od.department_id', $this->department_id);
        //         if(!empty($city_id)) {
        //             $query->where('offices.city_id', $city_id);
        //         }
        //         if(!empty($office_id)) {
        //             $query->where('offices.office_code', $office_id);
        //         }
        //         if(!empty($referral_ids) && count($referral_ids)) {
        //             $query->whereIn('referrals.referral_code', $referral_ids);
        //         }

        //         echo $query->toSql();exit;
        //         $data1 = $query->get();

        //         $ist_batch = [];
        //         foreach($data1 as $d) {
        //             if(!isset($ist_batch[$d->office_code.'-'.$d->referral_code])) {
        //                 $ist_batch[$d->office_code.'-'.$d->referral_code] = [
        //                     'office_code' => $d->office_code,
        //                     'office_name' => $d->office_name,
        //                     'city_id' => $d->city_id,
        //                     'city_name' => $d->city_name,
        //                     'referral_code' => $d->referral_code,
        //                     'referral_name' => $d->referral_name,
        //                     'interview_count' => $d->interview_count,
        //                     'estimated_amount' => $d->estimated_amount,
        //                 ];
        //             }
        //             elseif(isset($ist_batch[$d->office_code.'-'.$d->referral_code])) {
        //                 $existing_data = $ist_batch[$d->office_code.'-'.$d->referral_code];

        //                 $ist_batch[$d->office_code.'-'.$d->referral_code]['interview_count'] = $d->interview_count + $existing_data['interview_count'];
        //                 $ist_batch[$d->office_code.'-'.$d->referral_code]['estimated_amount'] = (int)($d->estimated_amount + $existing_data['estimated_amount']);
        //             }
        //         }
        //         // dd($ist_batch);
        //         /* ******************************************************************************************** */

        //         $query = DB::table(DB::raw("({$offices->toSql()}) as offices"))
        //                 ->selectRaw('
        //                     offices.office_code, 
        //                     offices.office_name, 
        //                     cities.id as city_id,
        //                     cities.name as city_name,
        //                     referrals.referral_code, 
        //                     referrals.referral_name,
        //                     COALESCE(contract_main_count.contract_count, 0) AS contract_count,
        //                     COALESCE(contract_main_count.order_amount, 0) AS order_amount'
        //                 )
        //                 ->mergeBindings($offices) // Important: merge bindings to include subquery parameters
        //                 ->crossJoin(DB::raw("({$referrals->toSql()}) as referrals"))
        //                 ->mergeBindings($referrals); // Important: merge bindings to include subquery parameters
        //         $query->distinct();

        //         $query->join('cities', 'offices.city_id', '=', 'cities.id');
        //         $query->join('office_departments as od', 'office_code', '=', 'od.office_id');

        //         // CALCULATING CONTRACT COUNT AND ORDER AMOUNT
        //         $yearCondInterviewForSelect = !empty($year) ? "EXTRACT(YEAR FROM int_det.interview_date) as year," : "";
        //         $monthCondInterviewForSelect = !empty($month) ? "EXTRACT(MONTH FROM int_det.interview_date) as month," : "";

        //         $yearConditionInterviewWhere = !empty($year) ? "AND EXTRACT(YEAR FROM int_det.interview_date) = '" . $year . "'" : "";
        //         $monthConditionInterviewWhere = !empty($month) ? "AND EXTRACT(MONTH FROM int_det.interview_date) = '" . $month . "' " : "";

        //         $yearCondInterviewForGroupBy = !empty($year) ? "EXTRACT(YEAR FROM int_det.interview_date)," : "";
        //         $monthCondInterviewForGroupBy = !empty($month) ? "EXTRACT(MONTH FROM int_det.interview_date)" : "";
        //         $query->leftJoin(
        //             DB::raw('LATERAL (
        //                 SELECT 
        //                     od.office_id as approach_office,
        //                     int.introduced_by_id as client_referral, ' .
        //                     $yearCondInterviewForSelect . ' ' .
        //                     $monthCondInterviewForSelect . '
        //                     COUNT(int_det.id) as contract_count,
        //                     SUM(psoz.interview_order_amount) as order_amount
        //                 FROM
        //                     interviews as int
        //                 LEFT JOIN LATERAL (
        //                     SELECT
        //                         main.*
        //                     FROM
        //                         interviews_detail as main
        //                     WHERE
        //                         deleted_at IS NULL
        //                         AND id = (
        //                             SELECT
        //                                 MIN(id)
        //                             FROM
        //                                 interviews_detail as sub
        //                             WHERE
        //                                 sub.deleted_at IS NULL
        //                                 AND sub.office_id = od.office_id
        //                                 AND main.interview_id = sub.interview_id
        //                         )
        //                         AND int.id = main.interview_id
        //                 ) as int_det on true
        //                 LEFT OUTER JOIN customers as client on
        //                     int.customer_id = client.id
                        
        //                 LEFT OUTER JOIN (
        //                     SELECT
        //                         interview_id,
        //                         interviewer1,
        //                         MAX(id)
        //                     FROM
        //                         interviews_detail
        //                     group by
        //                         interviewer1,interview_id
        //                 ) as member on
        //                     int.id = member.interview_id
        //                 LEFT OUTER JOIN users on
        //                     member.interviewer1 = users.id
        //                 LEFT JOIN project_sozoku as psoz ON
        //                         int.id = psoz.interview_id
        //                 INNER JOIN 
        //                     office_departments as od ON
        //                     int.office_departments_id = od.id AND od.department_id = ' . $this->department_id . '
        //                 INNER JOIN 
        //                     offices ON
        //                     od.office_id = offices.id
        //                 WHERE
        //                     int.deleted_at IS NULL AND
        //                     int.project_category = 1 AND
        //                     int.result_type = 2 ' .
        //                     $yearConditionInterviewWhere . ' ' .
        //                     $monthConditionInterviewWhere . '
        //                 GROUP BY
        //                     od.office_id,
        //                     int.introduced_by_id,' .
        //                     $yearCondInterviewForGroupBy . ' ' .
        //                     $monthCondInterviewForGroupBy . '
        //             ) as contract_main_count'), function($join) {
        //                 $join->on('offices.office_code', '=', 'contract_main_count.approach_office');
        //                 $join->on('referrals.referral_code', '=', 'contract_main_count.client_referral');
        //             }
        //         );

        //         $query->where('offices.status', 1);
        //         $query->where('od.status', 1);
        //         $query->where('od.department_id', $this->department_id);
        //         if(!empty($city_id)) {
        //             $query->where('offices.city_id', $city_id);
        //         }
        //         if(!empty($office_id)) {
        //             $query->where('offices.office_code', $office_id);
        //         }
        //         if(!empty($referral_ids) && count($referral_ids)) {
        //             $query->whereIn('referrals.referral_code', $referral_ids);
        //         }

        //         // echo $query->toSql();exit;
        //         $data2 = $query->get();

        //         $second_batch = [];
        //         foreach($data2 as $d) {
        //             if(!isset($second_batch[$d->office_code.'-'.$d->referral_code])) {
        //                 $second_batch[$d->office_code.'-'.$d->referral_code] = [
        //                     'office_code' => $d->office_code,
        //                     'office_name' => $d->office_name,
        //                     'city_id' => $d->city_id,
        //                     'city_name' => $d->city_name,
        //                     'referral_code' => $d->referral_code,
        //                     'referral_name' => $d->referral_name,
        //                     'contract_count' => $d->contract_count,
        //                     'order_amount' => $d->order_amount,
        //                 ];
        //             }
        //             elseif(isset($second_batch[$d->office_code.'-'.$d->referral_code])) {
        //                 $existing_data = $second_batch[$d->office_code.'-'.$d->referral_code];

        //                 $second_batch[$d->office_code.'-'.$d->referral_code]['contract_count'] = $d->contract_count + $existing_data['contract_count'];
        //                 $second_batch[$d->office_code.'-'.$d->referral_code]['order_amount'] = (int)($d->order_amount + $existing_data['order_amount']);
        //             }
        //         }
        //         // dd($ist_batch, $second_batch);

        //         $final_batch = [];
        //         $i = 0;
        //         foreach($ist_batch as $k => $data) {
        //             $final_batch[$i] = $data;
        //             if(isset($second_batch[$k])) {
        //                 $final_batch[$i]['contract_count'] = $second_batch[$k]['contract_count'];
        //                 $final_batch[$i]['contract_rate'] = $this->division($second_batch[$k]['contract_count'], $data['interview_count']);
        //                 $final_batch[$i]['order_amount'] = $second_batch[$k]['order_amount'];
        //             }
        //             else {
        //                 $final_batch[$i]['contract_count'] = 0;
        //                 $final_batch[$i]['contract_rate'] = 0;
        //                 $final_batch[$i]['order_amount'] = 0;
        //             }
        //             $i++;
        //         }

        //         // dd($final_batch);
        //         return $final_batch;
        //     } 
        //     catch (Exception $e) {
        //         $errorMessage = $e->getMessage();
        //         $errorFile = $e->getFile();
        //         $errorLine = $e->getLine();

        //         // Combine the error message with its location
        //         $errorDetails = [
        //             'message' => $errorMessage,
        //             'file' => $errorFile,
        //             'line' => $errorLine,
        //         ];

        //         // Assuming sendError is a method that can accept an array of error details
        //         return $this->sendError(__('something_went_wrong'), $errorDetails, 400);
        //     }
    // }

    // private function tab2Data($request, $dateOrYear, $getDataBy = 'month') 
    // {
    //     try {    
    //         $month = $year = '';
    //         if($getDataBy == 'month') {
    //             $month = $dateOrYear->format('m');
    //             $year = $dateOrYear->format('Y');
    //         }
    //         elseif($getDataBy == 'year') {
    //             $year = $dateOrYear;
    //         }

    //         $city_id = $request->input('city_id', '');
    //         $office_id = $request->input('office_id', '');
    //         $referral_ids = $request->input('referral_ids', '[]');
    //         $referral_ids = is_array($referral_ids) ? $referral_ids : [];
            
    //         // CALCULATING INTERVIEW COUNT AND ESTIMATED AMOUNT
    //         $yearCondInterviewForSelect = !empty($year) ? "EXTRACT(YEAR FROM int_det2.interview_date) as year," : "";
    //         $monthCondInterviewForSelect = !empty($month) ? "EXTRACT(MONTH FROM int_det2.interview_date) as month," : "";

    //         $yearCondInterviewForGroupBy = !empty($year) ? "EXTRACT(YEAR FROM int_det2.interview_date)," : "";
    //         $monthCondInterviewForGroupBy = !empty($month) ? "EXTRACT(MONTH FROM int_det2.interview_date)," : "";

    //         $bindings = [];
    //         $sql = "select distinct 
    //                 offices.office_code,
    //                 offices.office_name,
    //                 cities.id as city_id,
    //                 cities.name as city_name,
    //                 referrals.referral_code,
    //                 referrals.referral_name,
    //                 coalesce(interview_main_count.interview_count,	0) as interview_count,
    //                 coalesce(interview_main_count.estimated_amount,	0) as estimated_amount
    //             from
    //                 (
    //                 select
    //                     id as office_code,
    //                     name as office_name,
    //                     city_id,
    //                     status
    //                 from
    //                     offices) as offices
    //             cross join (
    //                 select
    //                     id as referral_code,
    //                     name as referral_name
    //                 from
    //                     introduced_by
    //                 where
    //                     type = 'tax') as referrals
    //             inner join cities on
    //                 offices.city_id = cities.id
    //             inner join office_departments as od on
    //                 office_code = od.office_id
    //             left join lateral (
    //                 select
    //                     od.office_id as approach_office,
    //                     int.introduced_by_id as client_referral,
    //                     {$yearCondInterviewForSelect}
    //                     {$monthCondInterviewForSelect}
    //                     COUNT(int_det2.id) as interview_count,
    //                     SUM(int_det.estimated_amount) as estimated_amount
    //                 from
    //                     interviews as int
    //                 left join lateral (
    //                     select
    //                         main.*
    //                     from
    //                         interviews_detail as main
    //                     where
    //                         deleted_at is null
    //                         and id = (
    //                         select
    //                             MAX(id)
    //                         from
    //                             interviews_detail as sub
    //                         where
    //                             sub.deleted_at is null ";

    //                         // if(empty($city_id) && empty($office_id) && count($referral_ids));
    //                         // elseif(empty($city_id) && empty($office_id) && count($referral_ids) == 0);
    //                         // else {
    //                         // }
    //                         if($getDataBy == 'all_years') {
    //                             $sql .= " and sub.office_id = od.office_id ";
    //                         }

    //             $sql .=  "      and main.interview_id = sub.interview_id )
    //                         and int.id = main.interview_id ) as int_det on
    //                     true
    //                 -- left outer join (
    //                 --     select
    //                 --         interview_id,
    //                 --         interviewer1,
    //                 --         MAX(id)
    //                 --     from
    //                 --         interviews_detail
    //                 --     group by
    //                 --         interviewer1,
    //                 --         interview_id ) as member on
    //                 --     int.id = member.interview_id
    //                 -- left outer join users on
    //                 --     member.interviewer1 = users.id
    //                 left join lateral (
    //                     select
    //                         main.*
    //                     from
    //                         interviews_detail as main
    //                     where
    //                         deleted_at is null
    //                         and id = (
    //                         select
    //                             MIN(id)
    //                         from
    //                             interviews_detail as sub
    //                         where
    //                             sub.deleted_at is null ";
                            
    //                         // if(empty($city_id) && empty($office_id) && count($referral_ids));
    //                         // elseif(empty($city_id) && empty($office_id) && count($referral_ids) == 0);
    //                         // else {
    //                         // }
    //                         $sql .= " and sub.office_id = od.office_id ";

    //                 $sql .= "   and main.interview_id = sub.interview_id )
    //                         and int.id = main.interview_id ) as int_det2 on
    //                     true
    //                 where ";
    //                     // if(empty($city_id) && empty($office_id) && count($referral_ids) == 0) {
    //                         // $sql .= "int.office_departments_id = od.id and ";
    //                     // }
    //                 $sql .=" od.department_id = " . $this->department_id . " and 
    //                     int.deleted_at is null and 
    //                     int.project_category = 1 and 
    //                     int.result_type != 1 ";
                        
    //             if(!empty($year)) {
    //                 $sql .= "AND EXTRACT(YEAR FROM int_det2.interview_date) =  ? ";
    //                 $bindings[] = $year;
    //             }
    //             if(!empty($month)) {
    //                 $sql .= "AND EXTRACT(MONTH FROM int_det2.interview_date) =  ? ";
    //                 $bindings[] = $month;
    //             }

    //             $sql .= " and offices.office_code = od.office_id
    //                     and referrals.referral_code = int.introduced_by_id
    //                 group by
    //                     od.office_id,
    //                     {$yearCondInterviewForGroupBy}
    //                     {$monthCondInterviewForGroupBy}
    //                     int.introduced_by_id
    //             ) as interview_main_count ON TRUE
    //             where
    //                 offices.status = 1
    //                 and od.status = 1
    //                 and od.department_id = " . $this->department_id . " ";
            
    //         if(!empty($city_id)) {
    //             $sql .= "AND offices.city_id = ? ";
    //             $bindings[] = $city_id;
    //         }
    //         if(!empty($office_id)) {
    //             $sql .= "AND offices.office_code = ? ";
    //             $bindings[] = $office_id;
    //         }
    //         if(!empty($referral_ids) && count($referral_ids)) {
    //             $placeholders = implode(', ', array_fill(0, count($referral_ids), '?'));
    //             $sql .= "AND referrals.referral_code IN ($placeholders) ";
    //             $bindings = array_merge($bindings, $referral_ids);
    //         }

    //         // if($getDataBy == 'all_years') {
    //         //     echo $sql;exit;
    //         //     dd($sql, $bindings);
    //         // }
            
    //         $data1 = DB::select($sql, $bindings);
    //         // dd($data1);
            
    //         $ist_batch = [];
    //         foreach($data1 as $d) {
    //             if(!isset($ist_batch[$d->office_code.'-'.$d->referral_code])) {
    //                 $ist_batch[$d->office_code.'-'.$d->referral_code] = [
    //                     'office_code' => $d->office_code,
    //                     'office_name' => $d->office_name,
    //                     'city_id' => $d->city_id,
    //                     'city_name' => $d->city_name,
    //                     'referral_code' => $d->referral_code,
    //                     'referral_name' => $d->referral_name,
    //                     'interview_count' => $d->interview_count,
    //                     'estimated_amount' => $d->estimated_amount,
    //                 ];
    //             }
    //             elseif(isset($ist_batch[$d->office_code.'-'.$d->referral_code])) {
    //                 $existing_data = $ist_batch[$d->office_code.'-'.$d->referral_code];

    //                 $ist_batch[$d->office_code.'-'.$d->referral_code]['interview_count'] = $d->interview_count + $existing_data['interview_count'];
    //                 $ist_batch[$d->office_code.'-'.$d->referral_code]['estimated_amount'] = (int)($d->estimated_amount + $existing_data['estimated_amount']);
    //             }
    //         }
    //         // dd($ist_batch);
    //         /* ******************************************************************************************** */

    //         // CALCULATING CONTRACT COUNT AND ORDER AMOUNT
    //         $yearCondInterviewForSelect = !empty($year) ? "EXTRACT(YEAR FROM int_det.interview_date) as year," : "";
    //         $monthCondInterviewForSelect = !empty($month) ? "EXTRACT(MONTH FROM int_det.interview_date) as month," : "";

    //         $yearCondInterviewForGroupBy = !empty($year) ? "EXTRACT(YEAR FROM int_det.interview_date)," : "";
    //         $monthCondInterviewForGroupBy = !empty($month) ? "EXTRACT(MONTH FROM int_det.interview_date)," : "";
           
    //         $bindings = [];
    //         $sql = "select
    //                 distinct offices.office_code,
    //                 offices.office_name,
    //                 cities.id as city_id,
    //                 cities.name as city_name,
    //                 referrals.referral_code,
    //                 referrals.referral_name,
    //                 CASE WHEN contract_main_count.contract_count > 0 THEN 1 ELSE 0 END as order_count,
    //                 coalesce(contract_main_count.contract_count, 0) as contract_count,
    //                 coalesce(contract_main_count.order_amount, 0) as order_amount
    //             from
    //                 (
    //                 select
    //                     id as office_code,
    //                     name as office_name,
    //                     city_id,
    //                     status
    //                 from
    //                     offices) as offices
    //             cross join (
    //                 select
    //                     id as referral_code,
    //                     name as referral_name
    //                 from
    //                     introduced_by
    //                 where
    //                     type = 'tax') as referrals
    //             inner join cities on
    //                 offices.city_id = cities.id
    //             inner join office_departments as od on
    //                 office_code = od.office_id
    //             left join lateral (
    //                 select
    //                     od.office_id as approach_office,
    //                     int.introduced_by_id as client_referral,
    //                     {$yearCondInterviewForSelect}
    //                     {$monthCondInterviewForSelect}
    //                     COUNT(int_det.id) as contract_count,
    //                     SUM(psoz.interview_order_amount) as order_amount
    //                 from
    //                     interviews as int
    //                 left join lateral (
    //                     select
    //                         main.*
    //                     from
    //                         interviews_detail as main
    //                     where
    //                         deleted_at is null
    //                         and id = (
    //                         select
    //                             MIN(id)
    //                         from
    //                             interviews_detail as sub
    //                         where
    //                             sub.deleted_at is null ";

    //                         // if(empty($city_id) && empty($office_id) && count($referral_ids));
    //                         // elseif(empty($city_id) && empty($office_id) && count($referral_ids) == 0);
    //                         // else {
    //                         // }
    //                         $sql .= " and sub.office_id = od.office_id ";
    //                 $sql .= "   and main.interview_id = sub.interview_id )
    //                         and int.id = main.interview_id ) as int_det on true
    //                 -- left outer join (
    //                 --     select
    //                 --         interview_id,
    //                 --         interviewer1,
    //                 --         MAX(id)
    //                 --     from
    //                 --         interviews_detail
    //                 --     group by
    //                 --         interviewer1,
    //                 --         interview_id ) as member on
    //                 --     int.id = member.interview_id
    //                 -- left outer join users on
    //                 --     member.interviewer1 = users.id
    //                 left join lateral (
    //                     select * 
    //                     from 
    //                         project_sozoku ps
    //                     where 
    //                         int.id = ps.interview_id
    //                         and ps.deleted_at is null ";
    //                         if($getDataBy == 'all_years') {
    //                             $sql .= " and ps.office_departments_id = od.id ";
    //                         }
    //             $sql .= ") as psoz on true
    //                 where ";
    //                 // if(empty($city_id) && empty($office_id) && count($referral_ids) == 0) {
    //                     // $sql .= "int.office_departments_id = od.id and ";
    //                 // }
    //             $sql .=" od.department_id = " . $this->department_id . " and 
    //                     int.deleted_at is null and 
    //                     int.project_category = 1 and 
    //                     int.result_type = 2 ";
                
    //             if(!empty($year)) {
    //                 $sql .= "AND EXTRACT(YEAR FROM int_det.interview_date) =  ? ";
    //                 $bindings[] = $year;
    //             }
    //             if(!empty($month)) {
    //                 $sql .= "AND EXTRACT(MONTH FROM int_det.interview_date) =  ? ";
    //                 $bindings[] = $month;
    //             }

    //             $sql .= " and offices.office_code = od.office_id
    //                     and referrals.referral_code = int.introduced_by_id
    //                 group by
    //                     od.office_id,
    //                     {$yearCondInterviewForGroupBy}
    //                     {$monthCondInterviewForGroupBy}
    //                     int.introduced_by_id
    //             ) as contract_main_count on true 
    //             where
    //                 offices.status = 1
    //                 and od.status = 1
    //                 and od.department_id = " . $this->department_id . " ";

    //         if(!empty($city_id)) {
    //             $sql .= "AND offices.city_id = ? ";
    //             $bindings[] = $city_id;
    //         }
    //         if(!empty($office_id)) {
    //             $sql .= "AND offices.office_code = ? ";
    //             $bindings[] = $office_id;
    //         }
    //         if(!empty($referral_ids) && count($referral_ids)) {
    //             $placeholders = implode(', ', array_fill(0, count($referral_ids), '?'));
    //             $sql .= "AND referrals.referral_code IN ($placeholders) ";
    //             $bindings = array_merge($bindings, $referral_ids);
    //         }

    //         // if($getDataBy == 'all_years') {
    //         //     echo $sql;exit;
    //         //     dd($sql, $bindings);
    //         // }
    //         $data2 = DB::select($sql, $bindings);
    //         // dd($data2);

    //         $second_batch = [];
    //         foreach($data2 as $d) {
    //             if(!isset($second_batch[$d->office_code.'-'.$d->referral_code])) {
    //                 $second_batch[$d->office_code.'-'.$d->referral_code] = [
    //                     'office_code' => $d->office_code,
    //                     'office_name' => $d->office_name,
    //                     'city_id' => $d->city_id,
    //                     'city_name' => $d->city_name,
    //                     'referral_code' => $d->referral_code,
    //                     'referral_name' => $d->referral_name,
    //                     'order_count' => $d->order_count,
    //                     'contract_count' => $d->contract_count,
    //                     'order_amount' => $d->order_amount,
    //                 ];
    //             }
    //             elseif(isset($second_batch[$d->office_code.'-'.$d->referral_code])) {
    //                 $existing_data = $second_batch[$d->office_code.'-'.$d->referral_code];

    //                 $second_batch[$d->office_code.'-'.$d->referral_code]['order_count'] = $d->order_count + $existing_data['order_count'];
    //                 $second_batch[$d->office_code.'-'.$d->referral_code]['contract_count'] = $d->contract_count + $existing_data['contract_count'];
    //                 $second_batch[$d->office_code.'-'.$d->referral_code]['order_amount'] = (int)($d->order_amount + $existing_data['order_amount']);
    //             }
    //         }
    //         // dd($second_batch);

    //         $final_batch = [];
    //         $i = 0;
    //         foreach($ist_batch as $k => $data) {
    //             $final_batch[$i] = $data;
    //             if(isset($second_batch[$k])) {
    //                 $final_batch[$i]['order_count'] = $second_batch[$k]['order_count'];
    //                 $final_batch[$i]['contract_count'] = $second_batch[$k]['contract_count'];
    //                 // $final_batch[$i]['contract_rate'] = $this->division($second_batch[$k]['order_count'], $data['interview_count']);
    //                 $final_batch[$i]['order_amount'] = $second_batch[$k]['order_amount'];
    //             }
    //             else {
    //                 $final_batch[$i]['order_count'] = 0;
    //                 $final_batch[$i]['contract_count'] = 0;
    //                 // $final_batch[$i]['contract_rate'] = 0;
    //                 $final_batch[$i]['order_amount'] = 0;
    //             }
    //             $i++;
    //         }

    //         // dd($final_batch);
    //         return $final_batch;
    //     } 
    //     catch (Exception $e) {
    //         $errorMessage = $e->getMessage();
    //         $errorFile = $e->getFile();
    //         $errorLine = $e->getLine();

    //         // Combine the error message with its location
    //         $errorDetails = [
    //             'message' => $errorMessage,
    //             'file' => $errorFile,
    //             'line' => $errorLine,
    //         ];

    //         // Assuming sendError is a method that can accept an array of error details
    //         return $this->sendError(__('something_went_wrong'), $errorDetails, 400);
    //     }
    // }

    private function tab2Data($request, $dateOrYear, $getDataBy = 'month') 
    {
        try {    
            $month = $year = '';
            if($getDataBy == 'month') {
                $month = $dateOrYear->format('m');
                $year = $dateOrYear->format('Y');
            }
            elseif($getDataBy == 'year') {
                $year = $dateOrYear;
            }

            $city_id = $request->input('city_id', '');
            $office_id = $request->input('office_id', '');
            $referral_ids = $request->input('referral_ids', '[]');
            $referral_ids = is_array($referral_ids) ? $referral_ids : [];
            
            // CALCULATING INTERVIEW COUNT AND ESTIMATED AMOUNT
            $yearCondInterviewForSelect = !empty($year) ? "EXTRACT(YEAR FROM int_det2.interview_date) as year," : "";
            $monthCondInterviewForSelect = !empty($month) ? "EXTRACT(MONTH FROM int_det2.interview_date) as month," : "";

            $yearCondInterviewForGroupBy = !empty($year) ? "EXTRACT(YEAR FROM int_det2.interview_date)," : "";
            $monthCondInterviewForGroupBy = !empty($month) ? "EXTRACT(MONTH FROM int_det2.interview_date)," : "";

            $bindings = [];
            $sql = "select distinct 
                    offices.office_code,
                    offices.office_name,
                    cities.id as city_id,
                    cities.name as city_name,
                    referrals.referral_code,
                    referrals.referral_name,
                    coalesce(interview_main_count.interview_count,	0) as interview_count,
                    coalesce(interview_main_count.estimated_amount,	0) as estimated_amount
                from
                    (
                    select
                        id as office_code,
                        name as office_name,
                        city_id,
                        status
                    from
                        offices) as offices
                cross join (
                    select
                        id as referral_code,
                        name as referral_name
                    from
                        introduced_by
                    where
                        type = 'tax') as referrals
                inner join cities on
                    offices.city_id = cities.id
                inner join office_departments as od on
                    office_code = od.office_id
                left join lateral (
                    select
                        od.office_id as approach_office,
                        int.introduced_by_id as client_referral,
                        {$yearCondInterviewForSelect}
                        {$monthCondInterviewForSelect}
                        COUNT(int_det2.id) as interview_count,
                        SUM(int_det.estimated_amount) as estimated_amount
                    from
                        interviews as int
                    left join lateral (
                        select
                            main.*
                        from
                            interviews_detail as main
                        where
                            deleted_at is null
                            and id = (
                            select
                                MAX(id)
                            from
                                interviews_detail as sub
                            where
                                sub.deleted_at is null ";
                            if($getDataBy == 'all_years') {
                                // $sql .= " and sub.office_id = od.office_id ";
                            }

                $sql .=  "      and main.interview_id = sub.interview_id )
                            and int.id = main.interview_id 
                            and main.office_id = od.office_id 
                    ) as int_det on true
                    left join lateral (
                        select
                            main.*
                        from
                            interviews_detail as main
                        where
                            deleted_at is null
                            and id = (
                                select
                                    MIN(id)
                                from
                                    interviews_detail as sub
                                where
                                    sub.deleted_at is null 
                                    -- and sub.office_id = od.office_id 
                                    and main.interview_id = sub.interview_id 
                            )
                            and int.id = main.interview_id 
                            and main.office_id = od.office_id 
                    ) as int_det2 on true
                    where 
                        od.department_id = " . $this->department_id . " and 
                        int.department_id = " . $this->department_id . " and 
                        int.deleted_at is null and 
                        int.project_category = 1 and 
                        int.result_type != 1 ";
                        
                if(!empty($year)) {
                    $sql .= "AND EXTRACT(YEAR FROM int_det2.interview_date) =  ? ";
                    $bindings[] = $year;
                }
                if(!empty($month)) {
                    $sql .= "AND EXTRACT(MONTH FROM int_det2.interview_date) =  ? ";
                    $bindings[] = $month;
                }

                $sql .= " and offices.office_code = od.office_id
                        and referrals.referral_code = int.introduced_by_id
                    group by
                        od.office_id,
                        {$yearCondInterviewForGroupBy}
                        {$monthCondInterviewForGroupBy}
                        int.introduced_by_id,
                        int.office_departments_id
                ) as interview_main_count ON TRUE
                where
                    offices.status = 1
                    and od.status = 1
                    and od.department_id = " . $this->department_id . " ";
            
            if(!empty($city_id)) {
                $sql .= "AND offices.city_id = ? ";
                $bindings[] = $city_id;
            }
            if(!empty($office_id)) {
                $sql .= "AND offices.office_code = ? ";
                $bindings[] = $office_id;
            }
            if(!empty($referral_ids) && count($referral_ids)) {
                $placeholders = implode(', ', array_fill(0, count($referral_ids), '?'));
                $sql .= "AND referrals.referral_code IN ($placeholders) ";
                $bindings = array_merge($bindings, $referral_ids);
            }

                // echo $sql;exit;
            // if($getDataBy == 'all_years') {
            //     dd($sql, $bindings);
            // }
            
            $data1 = DB::select($sql, $bindings);
            // dd($data1);
            
            $ist_batch = [];
            foreach($data1 as $d) {
                if(!isset($ist_batch[$d->office_code.'-'.$d->referral_code])) {
                    $ist_batch[$d->office_code.'-'.$d->referral_code] = [
                        'office_code' => $d->office_code,
                        'office_name' => $d->office_name,
                        'city_id' => $d->city_id,
                        'city_name' => $d->city_name,
                        'referral_code' => $d->referral_code,
                        'referral_name' => $d->referral_name,
                        'interview_count' => $d->interview_count,
                        'estimated_amount' => $d->estimated_amount,
                    ];
                }
                elseif(isset($ist_batch[$d->office_code.'-'.$d->referral_code])) {
                    $existing_data = $ist_batch[$d->office_code.'-'.$d->referral_code];

                    $ist_batch[$d->office_code.'-'.$d->referral_code]['interview_count'] = $d->interview_count + $existing_data['interview_count'];
                    $ist_batch[$d->office_code.'-'.$d->referral_code]['estimated_amount'] = (int)($d->estimated_amount + $existing_data['estimated_amount']);
                }
            }
            // dd($ist_batch);
            /* ******************************************************************************************** */

            // CALCULATING CONTRACT COUNT AND ORDER AMOUNT
            $yearCondInterviewForSelect = !empty($year) ? "EXTRACT(YEAR FROM int_det.interview_date) as year," : "";
            $monthCondInterviewForSelect = !empty($month) ? "EXTRACT(MONTH FROM int_det.interview_date) as month," : "";

            $yearCondInterviewForGroupBy = !empty($year) ? "EXTRACT(YEAR FROM int_det.interview_date)," : "";
            $monthCondInterviewForGroupBy = !empty($month) ? "EXTRACT(MONTH FROM int_det.interview_date)," : "";
           
            $bindings = [];
            $sql = "select
                    distinct offices.office_code,
                    offices.office_name,
                    cities.id as city_id,
                    cities.name as city_name,
                    referrals.referral_code,
                    referrals.referral_name,
                    contract_main_count.contract_count AS order_count,
                    coalesce(contract_main_count.contract_count, 0) as contract_count,
                    coalesce(contract_main_count.order_amount, 0) as order_amount
                from
                    (
                    select
                        id as office_code,
                        name as office_name,
                        city_id,
                        status
                    from
                        offices) as offices
                cross join (
                    select
                        id as referral_code,
                        name as referral_name
                    from
                        introduced_by
                    where
                        type = 'tax') as referrals
                inner join cities on
                    offices.city_id = cities.id
                inner join office_departments as od on
                    office_code = od.office_id
                left join lateral (
                    select
                        od.office_id as approach_office,
                        int.introduced_by_id as client_referral,
                        {$yearCondInterviewForSelect}
                        {$monthCondInterviewForSelect}
                        SUM(CASE WHEN int_det.id > 0 THEN 1 ELSE 0 end) AS order_count,
                        COUNT(int_det.id) as contract_count,
                        SUM(int_det.order_amount) as order_amount
                    from
                        interviews as int
                    left join lateral (
                        select
                            main.*,
                            COALESCE(psoz.order_amount, 0) AS order_amount
                        from
                            interviews_detail as main
                        LEFT JOIN (
                            SELECT
                                ps.interview_id,
                                ps.id AS project_id,
                                ps.interview_order_amount AS order_amount
                            FROM
                                project_sozoku ps
                            WHERE
                                ps.deleted_at IS NULL
                        ) AS psoz ON main.interview_id = psoz.interview_id
                        where
                            main.deleted_at is null
                            and main.id = (
                                select
                                    MIN(id)
                                from
                                    interviews_detail as sub
                                where
                                    sub.deleted_at is null  
                                    -- and sub.office_id = od.office_id 
                                    and main.interview_id = sub.interview_id 
                            )
                            and int.id = main.interview_id 
                            and main.office_id = od.office_id 
                    ) as int_det on true ";
                $sql .=" WHERE
                        od.department_id = " . $this->department_id . " and 
                        int.department_id = " . $this->department_id . " and 
                        int.deleted_at is null and 
                        int.project_category = 1 and 
                        int.result_type = 2 ";
                
                if(!empty($year)) {
                    $sql .= "AND EXTRACT(YEAR FROM int_det.interview_date) =  ? ";
                    $bindings[] = $year;
                }
                if(!empty($month)) {
                    $sql .= "AND EXTRACT(MONTH FROM int_det.interview_date) =  ? ";
                    $bindings[] = $month;
                }

                $sql .= " and offices.office_code = od.office_id
                        and referrals.referral_code = int.introduced_by_id
                    group by
                        od.office_id,
                        {$yearCondInterviewForGroupBy}
                        {$monthCondInterviewForGroupBy}
                        int.introduced_by_id
                ) as contract_main_count on true 
                where
                    offices.status = 1
                    and od.status = 1
                    and od.department_id = " . $this->department_id . " ";

            if(!empty($city_id)) {
                $sql .= "AND offices.city_id = ? ";
                $bindings[] = $city_id;
            }
            if(!empty($office_id)) {
                $sql .= "AND offices.office_code = ? ";
                $bindings[] = $office_id;
            }
            if(!empty($referral_ids) && count($referral_ids)) {
                $placeholders = implode(', ', array_fill(0, count($referral_ids), '?'));
                $sql .= "AND referrals.referral_code IN ($placeholders) ";
                $bindings = array_merge($bindings, $referral_ids);
            }

            // if($getDataBy == 'all_years') {
            //     echo $sql;exit;
            //     dd($sql, $bindings);
            // }
            $data2 = DB::select($sql, $bindings);
            // dd($data2);

            $second_batch = [];
            foreach($data2 as $d) {
                if(!isset($second_batch[$d->office_code.'-'.$d->referral_code])) {
                    $second_batch[$d->office_code.'-'.$d->referral_code] = [
                        'office_code' => $d->office_code,
                        'office_name' => $d->office_name,
                        'city_id' => $d->city_id,
                        'city_name' => $d->city_name,
                        'referral_code' => $d->referral_code,
                        'referral_name' => $d->referral_name,
                        'order_count' => $d->order_count,
                        'contract_count' => $d->contract_count,
                        'order_amount' => $d->order_amount,
                    ];
                }
                elseif(isset($second_batch[$d->office_code.'-'.$d->referral_code])) {
                    $existing_data = $second_batch[$d->office_code.'-'.$d->referral_code];

                    $second_batch[$d->office_code.'-'.$d->referral_code]['order_count'] = $d->order_count + $existing_data['order_count'];
                    $second_batch[$d->office_code.'-'.$d->referral_code]['contract_count'] = $d->contract_count + $existing_data['contract_count'];
                    $second_batch[$d->office_code.'-'.$d->referral_code]['order_amount'] = (int)($d->order_amount + $existing_data['order_amount']);
                }
            }
            // dd($second_batch);

            $final_batch = [];
            $i = 0;
            foreach($ist_batch as $k => $data) {
                $final_batch[$i] = $data;
                if(isset($second_batch[$k])) {
                    $final_batch[$i]['order_count'] = $second_batch[$k]['order_count'];
                    $final_batch[$i]['contract_count'] = $second_batch[$k]['contract_count'];
                    // $final_batch[$i]['contract_rate'] = $this->division($second_batch[$k]['order_count'], $data['interview_count']);
                    $final_batch[$i]['order_amount'] = $second_batch[$k]['order_amount'];
                }
                else {
                    $final_batch[$i]['order_count'] = 0;
                    $final_batch[$i]['contract_count'] = 0;
                    // $final_batch[$i]['contract_rate'] = 0;
                    $final_batch[$i]['order_amount'] = 0;
                }
                $i++;
            }

            // dd($final_batch);
            return $final_batch;
        } 
        catch (Exception $e) {
            $errorMessage = $e->getMessage();
            $errorFile = $e->getFile();
            $errorLine = $e->getLine();

            // Combine the error message with its location
            $errorDetails = [
                'message' => $errorMessage,
                'file' => $errorFile,
                'line' => $errorLine,
            ];

            // Assuming sendError is a method that can accept an array of error details
            return $this->sendError(__('something_went_wrong'), $errorDetails, 400);
        }
    }

    /**
     * Current loop record - $cur_rec
     * Existing record - $ex_rec
     */
    private function tab2Array($cur_rec, $ex_rec = [])
    {
        if(count($ex_rec)) {
            $_interview_count = $cur_rec['interview_count'] + $ex_rec['interview_count'];
            $_contract_count = $cur_rec['contract_count'] + $ex_rec['contract_count'];
            $_order_count = $cur_rec['order_count'] + $ex_rec['order_count'];

            $_estimated_amount = str_replace(",", "", $cur_rec['estimated_amount']) + str_replace(",", "", $ex_rec['estimated_amount']);
            $_order_amount = str_replace(",", "", $cur_rec['order_amount']) + str_replace(",", "", $ex_rec['order_amount']);

            $data = [
                'interview_count'   => $_interview_count,
                'estimated_amount'  => number_format($_estimated_amount, 0, '.', ','),
                'contract_count'    => $_contract_count,
                'contract_rate'     => $this->division($_order_count, $_interview_count),
                'order_amount'      => number_format($_order_amount, 0, '.', ','),
                'order_count'       => $_order_count,
            ];
        }
        else {
            $data = [
                'interview_count'   => $cur_rec['interview_count'],
                'estimated_amount'  => number_format($cur_rec['estimated_amount'], 0, '.', ','),
                'contract_count'    => $cur_rec['contract_count'],
                'contract_rate'     => $this->division($cur_rec['order_count'], $cur_rec['interview_count']),
                'order_amount'      => number_format($cur_rec['order_amount'], 0, '.', ','),
                'order_count'       => $cur_rec['order_count'],
            ];
        }

        return $data;
    }

    // TAB 3
    public function tab3(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'start_date' => 'required|date_format:Y-m',
                'end_date' => 'required|date_format:Y-m',
                'is_yearly_total' => 'in:1,0',
                'is_grand_total' => 'in:1,0',
                'search_type' => 'required|in:area,office,employee',
            ]);
        
            if ($validator->fails()) {
                $errors = $validator->errors();
                $error_array = [];
                if($errors->first('start_date')) {
                    $error_array['start_date'] = [$errors->first('start_date')];
                }
                if($errors->first('end_date')) {
                    $error_array['end_date'] = [$errors->first('end_date')];
                }
                if($errors->first('is_yearly_total')) {
                    $error_array['is_yearly_total'] = [$errors->first('is_yearly_total')];
                }
                if($errors->first('is_grand_total')) {
                    $error_array['is_grand_total'] = [$errors->first('is_grand_total')];
                }
                if($errors->first('search_type')) {
                    $error_array['search_type'] = [$errors->first('search_type')];
                }
                return $this->sendError(__('something_went_wrong'), $error_array, 422);
            }

            $search_type = $request->input('search_type', '');      // Possible values area, office, employee
            $is_yearly_total = $request->input('is_yearly_total', 0);
            $is_grand_total = $request->input('is_grand_total', 0);

            // Assuming $start_date and $end_date are in 'Y-m' format, e.g., '2023-12'
            $start_date = Carbon::createFromFormat('Y-m-d', $request->start_date.'-01');
            $end_date = Carbon::createFromFormat('Y-m-d', $request->end_date.'-01')->endOfMonth(); // Ensure the end date covers the end of the month

            $currentDate = $start_date->copy();
            $previousYear = $start_date->year;

            // RESPONSE DATA CODE
            $response_data = [];            
            // Initial run for Function
            $response_data[$currentDate->year][$currentDate->format('m')] = $this->tab3Data($request, $currentDate, 'month');
            // Move to the next month for the loop start
            $currentDate->addMonth();
            
            while ($currentDate->lessThanOrEqualTo($end_date)) {
                // Check if the year has changed, indicating the start of a new year
                if ($currentDate->year != $previousYear && $is_yearly_total == 1) {
                    // Run Function for total of year calculation before processing the new year
                    $response_data[$previousYear]['year_total'] = $this->tab3Data($request, $previousYear, 'year');
                    $previousYear = $currentDate->year;
                }

                // Run Function for the current month
                $response_data[$currentDate->year][$currentDate->format('m')] = $this->tab3Data($request, $currentDate, 'month');

                // Move to the next month
                $currentDate->addMonth();
            }

            // After completing the loop, check if Function needs to be called for the end date's year
            if ($end_date->year == $previousYear && $is_yearly_total == 1) {
                $response_data[$end_date->year]['year_total'] = $this->tab3Data($request, $end_date->year, 'year');
            }
            
            // For grand total row wise
            if($is_grand_total == 1) {
                $response_data['all_years']['grand_total'] = $this->tab3Data($request, $end_date->year, 'all_years');
            }
            // dd($response_data);

            // ALL DATA
            $worker_all_data = $teams_all_data = $non_teams_all_data = $inactive_workers_data = $others_all_data = $offices_all_data = $area_all_data = $total_column_data = [];
            foreach($response_data as $year => $record) {
                
                if(is_array($record)) {
                    foreach($record as $month => $worker_records) {

                        if(is_array($worker_records)) {
                            foreach($worker_records as $w_rec) {
                                $worker_check = $others_check = 0;
                                // ALL WORKERs DATA
                                // if($w_rec->status == 1 && $w_rec->office_deleted == null && $search_type == 'employee') {
                                if($w_rec->status == 1 && $search_type == 'employee') {
                                    $worker_check = 1;
                                    if(!isset($worker_all_data[$w_rec->id.'-'.$w_rec->office_id]) && $month != 'year_total' && $month != 'grand_total') {
                                        $worker_all_data[$w_rec->id.'-'.$w_rec->office_id] = [
                                            'Base' => $w_rec->office_name,
                                            'Team' => !empty($w_rec->team_name) ? $w_rec->team_name : '',
                                            'Worker' => $w_rec->mc_name,
                                            $this->months[$month] . ' ' . $year => $this->tab3Array($w_rec)
                                        ];
                                    }
                                    elseif(isset($worker_all_data[$w_rec->id.'-'.$w_rec->office_id]) && $month != 'year_total' && $month != 'grand_total') {
                                        $worker_all_data[$w_rec->id.'-'.$w_rec->office_id][$this->months[$month] . ' ' . $year] = $this->tab3Array($w_rec);
                                    }
                                    elseif(isset($worker_all_data[$w_rec->id.'-'.$w_rec->office_id]) && $month == 'year_total') {
                                        $worker_all_data[$w_rec->id.'-'.$w_rec->office_id][$year . ' Total'] = $this->tab3Array($w_rec);
                                    }
                                    elseif(isset($worker_all_data[$w_rec->id.'-'.$w_rec->office_id]) && $month == 'grand_total') {
                                        $worker_all_data[$w_rec->id.'-'.$w_rec->office_id]['Grand Total'] = $this->tab3Array($w_rec);
                                    }
                                }

                                // ALL TEAMS DATA
                                /* if($w_rec->status == 1 && !empty($w_rec->team_id) && $w_rec->office_deleted == null && $search_type == 'employee') {
                                    $worker_check = 1;
                                    if(!isset($teams_all_data['team-' . $w_rec->team_id]) && $month != 'year_total' && $month != 'grand_total') {
                                        $teams_all_data['team-' . $w_rec->team_id] = [
                                            'Base' => $w_rec->office_name,
                                            'Team' => $w_rec->team_name,
                                            'Worker' => __('totalling'),
                                            $this->months[$month] . ' ' . $year => $this->tab3Array($w_rec)
                                        ];
                                    }
                                    elseif(isset($teams_all_data['team-' . $w_rec->team_id]) && isset($this->months[$month]) && !isset($teams_all_data['team-' . $w_rec->team_id][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $teams_all_data['team-' . $w_rec->team_id][$this->months[$month] . ' ' . $year] = $this->tab3Array($w_rec);
                                    }
                                    elseif(isset($teams_all_data['team-' . $w_rec->team_id]) && !isset($teams_all_data['team-' . $w_rec->team_id][$year . ' Total']) && $month == 'year_total') {
                                        $teams_all_data['team-' . $w_rec->team_id][$year . ' Total'] = $this->tab3Array($w_rec);
                                    }
                                    elseif(isset($teams_all_data['team-' . $w_rec->team_id]) && !isset($teams_all_data['team-' . $w_rec->team_id]['Grand Total']) && $month == 'grand_total') {
                                        $teams_all_data['team-' . $w_rec->team_id]['Grand Total'] = $this->tab3Array($w_rec);
                                    }
                                    elseif(isset($teams_all_data['team-' . $w_rec->team_id]) && isset($this->months[$month]) && isset($teams_all_data['team-' . $w_rec->team_id][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $teams_all_data['team-' . $w_rec->team_id][$this->months[$month] . ' ' . $year];
                                        $teams_all_data['team-' . $w_rec->team_id][$this->months[$month] . ' ' . $year] = $this->tab3Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($teams_all_data['team-' . $w_rec->team_id]) && isset($teams_all_data['team-' . $w_rec->team_id][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $teams_all_data['team-' . $w_rec->team_id][$year . ' Total'];
                                        $teams_all_data['team-' . $w_rec->team_id][$year . ' Total'] = $this->tab3Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($teams_all_data['team-' . $w_rec->team_id]) && isset($teams_all_data['team-' . $w_rec->team_id]['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $teams_all_data['team-' . $w_rec->team_id]['Grand Total'];
                                        $teams_all_data['team-' . $w_rec->team_id]['Grand Total'] = $this->tab3Array($w_rec, $existing_array);
                                    }
                                }

                                // ALL NON-TEAMS DATA
                                if($w_rec->status == 1 && empty($w_rec->team_id) && $w_rec->office_deleted == null && $search_type == 'employee') {
                                    $worker_check = 1;
                                    if(!isset($non_teams_all_data['non-team-office-' . $w_rec->office_id]) && $month != 'year_total' && $month != 'grand_total') {
                                        $non_teams_all_data['non-team-office-' . $w_rec->office_id] = [
                                            'Base' => $w_rec->office_name,
                                            'Team' => __('not_a_team_member'),
                                            'Worker' => __('totalling'),
                                            $this->months[$month] . ' ' . $year => $this->tab3Array($w_rec)
                                        ];
                                    }
                                    elseif(isset($non_teams_all_data['non-team-office-' . $w_rec->office_id]) && isset($this->months[$month]) && !isset($non_teams_all_data['non-team-office-' . $w_rec->office_id][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $non_teams_all_data['non-team-office-' . $w_rec->office_id][$this->months[$month] . ' ' . $year] = $this->tab3Array($w_rec);
                                    }
                                    elseif(isset($non_teams_all_data['non-team-office-' . $w_rec->office_id]) && !isset($non_teams_all_data['non-team-office-' . $w_rec->office_id][$year . ' Total']) && $month == 'year_total') {
                                        $non_teams_all_data['non-team-office-' . $w_rec->office_id][$year . ' Total'] = $this->tab3Array($w_rec);
                                    }
                                    elseif(isset($non_teams_all_data['non-team-office-' . $w_rec->office_id]) && !isset($non_teams_all_data['non-team-office-' . $w_rec->office_id]['Grand Total']) && $month == 'grand_total') {
                                        $non_teams_all_data['non-team-office-' . $w_rec->office_id]['Grand Total'] = $this->tab3Array($w_rec);
                                    }
                                    elseif(isset($non_teams_all_data['non-team-office-' . $w_rec->office_id]) && isset($this->months[$month]) && isset($non_teams_all_data['non-team-office-' . $w_rec->office_id][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $non_teams_all_data['non-team-office-' . $w_rec->office_id][$this->months[$month] . ' ' . $year];
                                        $non_teams_all_data['non-team-office-' . $w_rec->office_id][$this->months[$month] . ' ' . $year] = $this->tab3Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($non_teams_all_data['non-team-office-' . $w_rec->office_id]) && isset($non_teams_all_data['non-team-office-' . $w_rec->office_id][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $non_teams_all_data['non-team-office-' . $w_rec->office_id][$year . ' Total'];
                                        $non_teams_all_data['non-team-office-' . $w_rec->office_id][$year . ' Total'] = $this->tab3Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($non_teams_all_data['non-team-office-' . $w_rec->office_id]) && isset($non_teams_all_data['non-team-office-' . $w_rec->office_id]['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $non_teams_all_data['non-team-office-' . $w_rec->office_id]['Grand Total'];
                                        $non_teams_all_data['non-team-office-' . $w_rec->office_id]['Grand Total'] = $this->tab3Array($w_rec, $existing_array);
                                    }
                                } */

                                // ALL INACTIVE WORKERs DATA
                                /* if($w_rec->status == 0 && $search_type == 'employee') {
                                    $worker_check = 1;
                                    if(!isset($inactive_workers_data['inactive-workers-' . $w_rec->office_id]) && $month != 'year_total' && $month != 'grand_total') {
                                        $inactive_workers_data['inactive-workers-' . $w_rec->office_id] = [
                                            'Base' => $w_rec->office_name,
                                            'Team' => __('others'),
                                            'Worker' => __('totalling'),
                                            $this->months[$month] . ' ' . $year => $this->tab3Array($w_rec)
                                        ];
                                    }
                                    elseif(isset($inactive_workers_data['inactive-workers-' . $w_rec->office_id]) && isset($this->months[$month]) && !isset($inactive_workers_data['inactive-workers-' . $w_rec->office_id][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $inactive_workers_data['inactive-workers-' . $w_rec->office_id][$this->months[$month] . ' ' . $year] = $this->tab3Array($w_rec);
                                    }
                                    elseif(isset($inactive_workers_data['inactive-workers-' . $w_rec->office_id]) && !isset($inactive_workers_data['inactive-workers-' . $w_rec->office_id][$year . ' Total']) && $month == 'year_total') {
                                        $inactive_workers_data['inactive-workers-' . $w_rec->office_id][$year . ' Total'] = $this->tab3Array($w_rec);
                                    }
                                    elseif(isset($inactive_workers_data['inactive-workers-' . $w_rec->office_id]) && !isset($inactive_workers_data['inactive-workers-' . $w_rec->office_id]['Grand Total']) && $month == 'grand_total') {
                                        $inactive_workers_data['inactive-workers-' . $w_rec->office_id]['Grand Total'] = $this->tab3Array($w_rec);
                                    }
                                    elseif(isset($inactive_workers_data['inactive-workers-' . $w_rec->office_id]) && isset($this->months[$month]) && isset($inactive_workers_data['inactive-workers-' . $w_rec->office_id][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $inactive_workers_data['inactive-workers-' . $w_rec->office_id][$this->months[$month] . ' ' . $year];
                                        $inactive_workers_data['inactive-workers-' . $w_rec->office_id][$this->months[$month] . ' ' . $year] = $this->tab3Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($inactive_workers_data['inactive-workers-' . $w_rec->office_id]) && isset($inactive_workers_data['inactive-workers-' . $w_rec->office_id][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $inactive_workers_data['inactive-workers-' . $w_rec->office_id][$year . ' Total'];
                                        $inactive_workers_data['inactive-workers-' . $w_rec->office_id][$year . ' Total'] = $this->tab3Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($inactive_workers_data['inactive-workers-' . $w_rec->office_id]) && isset($inactive_workers_data['inactive-workers-' . $w_rec->office_id]['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $inactive_workers_data['inactive-workers-' . $w_rec->office_id]['Grand Total'];
                                        $inactive_workers_data['inactive-workers-' . $w_rec->office_id]['Grand Total'] = $this->tab3Array($w_rec, $existing_array);
                                    }
                                } */

                                // ALL OTHERS DATA
                                // if(($w_rec->status == 0 || !empty($w_rec->office_deleted)) && $search_type == 'employee') {
                                if(($w_rec->status == 0) && $search_type == 'employee') {
                                    $others_check = 1;
                                    if(!isset($others_all_data['others']) && $month != 'year_total' && $month != 'grand_total') {
                                        $others_all_data['others'] = [
                                            'Base' => '',
                                            'Team' => '',
                                            'Worker' => __('others'),
                                            $this->months[$month] . ' ' . $year => $this->tab3Array($w_rec)
                                        ];
                                    }
                                    elseif(isset($others_all_data['others']) && isset($this->months[$month]) && !isset($others_all_data['others'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $others_all_data['others'][$this->months[$month] . ' ' . $year] = $this->tab3Array($w_rec);
                                    }
                                    elseif(isset($others_all_data['others']) && !isset($others_all_data['others'][$year . ' Total']) && $month == 'year_total') {
                                        $others_all_data['others'][$year . ' Total'] = $this->tab3Array($w_rec);
                                    }
                                    elseif(isset($others_all_data['others']) && !isset($others_all_data['others']['Grand Total']) && $month == 'grand_total') {
                                        $others_all_data['others']['Grand Total'] = $this->tab3Array($w_rec);
                                    }
                                    elseif(isset($others_all_data['others']) && isset($this->months[$month]) && isset($others_all_data['others'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $others_all_data['others'][$this->months[$month] . ' ' . $year];
                                        $others_all_data['others'][$this->months[$month] . ' ' . $year] = $this->tab3Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($others_all_data['others']) && isset($others_all_data['others'][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $others_all_data['others'][$year . ' Total'];
                                        $others_all_data['others'][$year . ' Total'] = $this->tab3Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($others_all_data['others']) && isset($others_all_data['others']['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $others_all_data['others']['Grand Total'];
                                        $others_all_data['others']['Grand Total'] = $this->tab3Array($w_rec, $existing_array);
                                    }
                                }

                                // IF THERE IS NO OTHERS DATA IN CURRENT ITERATION THEN ADD A DEFAULT DATA
                                if($worker_check == 1 && $others_check == 0) {
                                    if(!isset($others_all_data['others']) && $month != 'year_total' && $month != 'grand_total') {
                                        $others_all_data['others'] = [
                                            'Base' => '',
                                            'Team' => '',
                                            'Worker' => __('others'),
                                            $this->months[$month] . ' ' . $year => $this->tab3DefaultArray()
                                        ];
                                    }
                                    elseif(isset($others_all_data['others']) && isset($this->months[$month]) && !isset($others_all_data['others'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $others_all_data['others'][$this->months[$month] . ' ' . $year] = $this->tab3DefaultArray();
                                    }
                                    elseif(isset($others_all_data['others']) && !isset($others_all_data['others'][$year . ' Total']) && $month == 'year_total') {
                                        $others_all_data['others'][$year . ' Total'] = $this->tab3DefaultArray();
                                    }
                                    elseif(isset($others_all_data['others']) && !isset($others_all_data['others']['Grand Total']) && $month == 'grand_total') {
                                        $others_all_data['others']['Grand Total'] = $this->tab3DefaultArray();
                                    }
                                }

                                // ALL AREAS DATA
                                if($search_type == 'area') {
                                    if(!isset($area_all_data['area-' . $w_rec->city_id]) && $month != 'year_total' && $month != 'grand_total') {
                                        $area_all_data['area-' . $w_rec->city_id] = [
                                            'Base' => $w_rec->city_name,
                                            'Team' => 'All',
                                            'Worker' => __('total'),
                                            $this->months[$month] . ' ' . $year => $this->tab3Array($w_rec)
                                        ];
                                    }
                                    elseif(isset($area_all_data['area-' . $w_rec->city_id]) && isset($this->months[$month]) && !isset($area_all_data['area-' . $w_rec->city_id][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $area_all_data['area-' . $w_rec->city_id][$this->months[$month] . ' ' . $year] = $this->tab3Array($w_rec);
                                    }
                                    elseif(isset($area_all_data['area-' . $w_rec->city_id]) && !isset($area_all_data['area-' . $w_rec->city_id][$year . ' Total']) && $month == 'year_total') {
                                        $area_all_data['area-' . $w_rec->city_id][$year . ' Total'] = $this->tab3Array($w_rec);
                                    }
                                    elseif(isset($area_all_data['area-' . $w_rec->city_id]) && !isset($area_all_data['area-' . $w_rec->city_id]['Grand Total']) && $month == 'grand_total') {
                                        $area_all_data['area-' . $w_rec->city_id]['Grand Total'] = $this->tab3Array($w_rec);
                                    }
                                    elseif(isset($area_all_data['area-' . $w_rec->city_id]) && isset($this->months[$month]) && isset($area_all_data['area-' . $w_rec->city_id][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $area_all_data['area-' . $w_rec->city_id][$this->months[$month] . ' ' . $year];
                                        $area_all_data['area-' . $w_rec->city_id][$this->months[$month] . ' ' . $year] = $this->tab3Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($area_all_data['area-' . $w_rec->city_id]) && isset($area_all_data['area-' . $w_rec->city_id][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $area_all_data['area-' . $w_rec->city_id][$year . ' Total'];
                                        $area_all_data['area-' . $w_rec->city_id][$year . ' Total'] = $this->tab3Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($area_all_data['area-' . $w_rec->city_id]) && isset($area_all_data['area-' . $w_rec->city_id]['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $area_all_data['area-' . $w_rec->city_id]['Grand Total'];
                                        $area_all_data['area-' . $w_rec->city_id]['Grand Total'] = $this->tab3Array($w_rec, $existing_array);
                                    }
                                }

                                // ALL OFFICES DATA
                                if($search_type == 'office') {
                                    if(!isset($offices_all_data['office-' . $w_rec->office_id]) && $month != 'year_total' && $month != 'grand_total') {
                                        $offices_all_data['office-' . $w_rec->office_id] = [
                                            'Base' => $w_rec->office_name,
                                            'Team' => 'All',
                                            'Worker' => __('total'),
                                            $this->months[$month] . ' ' . $year => $this->tab3Array($w_rec)
                                        ];
                                    }
                                    elseif(isset($offices_all_data['office-' . $w_rec->office_id]) && isset($this->months[$month]) && !isset($offices_all_data['office-' . $w_rec->office_id][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $offices_all_data['office-' . $w_rec->office_id][$this->months[$month] . ' ' . $year] = $this->tab3Array($w_rec);
                                    }
                                    elseif(isset($offices_all_data['office-' . $w_rec->office_id]) && !isset($offices_all_data['office-' . $w_rec->office_id][$year . ' Total']) && $month == 'year_total') {
                                        $offices_all_data['office-' . $w_rec->office_id][$year . ' Total'] = $this->tab3Array($w_rec);
                                    }
                                    elseif(isset($offices_all_data['office-' . $w_rec->office_id]) && !isset($offices_all_data['office-' . $w_rec->office_id]['Grand Total']) && $month == 'grand_total') {
                                        $offices_all_data['office-' . $w_rec->office_id]['Grand Total'] = $this->tab3Array($w_rec);
                                    }
                                    elseif(isset($offices_all_data['office-' . $w_rec->office_id]) && isset($this->months[$month]) && isset($offices_all_data['office-' . $w_rec->office_id][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $offices_all_data['office-' . $w_rec->office_id][$this->months[$month] . ' ' . $year];
                                        $offices_all_data['office-' . $w_rec->office_id][$this->months[$month] . ' ' . $year] = $this->tab3Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($offices_all_data['office-' . $w_rec->office_id]) && isset($offices_all_data['office-' . $w_rec->office_id][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $offices_all_data['office-' . $w_rec->office_id][$year . ' Total'];
                                        $offices_all_data['office-' . $w_rec->office_id][$year . ' Total'] = $this->tab3Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($offices_all_data['office-' . $w_rec->office_id]) && isset($offices_all_data['office-' . $w_rec->office_id]['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $offices_all_data['office-' . $w_rec->office_id]['Grand Total'];
                                        $offices_all_data['office-' . $w_rec->office_id]['Grand Total'] = $this->tab3Array($w_rec, $existing_array);
                                    }
                                }

                                // ALL TOTAL DATA
                                if(!isset($total_column_data['column_wise_total']) && $month != 'year_total' && $month != 'grand_total') {
                                    $total_column_data['column_wise_total'] = [
                                        'Base' => __('grand_total'),
                                        'Team' => '',
                                        'Worker' => '',
                                        $this->months[$month] . ' ' . $year => $this->tab3Array($w_rec)
                                    ];
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($this->months[$month]) && !isset($total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                    $total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year] = $this->tab3Array($w_rec);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && !isset($total_column_data['column_wise_total'][$year . ' Total']) && $month == 'year_total') {
                                    $total_column_data['column_wise_total'][$year . ' Total'] = $this->tab3Array($w_rec);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && !isset($total_column_data['column_wise_total']['Grand Total']) && $month == 'grand_total') {
                                    $total_column_data['column_wise_total']['Grand Total'] = $this->tab3Array($w_rec);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($this->months[$month]) && isset($total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                    $existing_array = $total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year];
                                    $total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year] = $this->tab3Array($w_rec, $existing_array);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($total_column_data['column_wise_total'][$year . ' Total']) && $month == 'year_total') {
                                    $existing_array = $total_column_data['column_wise_total'][$year . ' Total'];
                                    $total_column_data['column_wise_total'][$year . ' Total'] = $this->tab3Array($w_rec, $existing_array);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($total_column_data['column_wise_total']['Grand Total']) && $month == 'grand_total') {
                                    $existing_array = $total_column_data['column_wise_total']['Grand Total'];
                                    $total_column_data['column_wise_total']['Grand Total'] = $this->tab3Array($w_rec, $existing_array);
                                }
                            }
                        }
                    }
                }
            }
            // dd($worker_all_data, $teams_all_data);

            $data = [
                'search_type' => $search_type,
                'worker_data' => count($worker_all_data) ? $worker_all_data : (object)[],
                'teams_data' => count($teams_all_data) ? $teams_all_data : (object)[],
                'non_teams_data' => count($non_teams_all_data) ? $non_teams_all_data : (object)[],
                'inactive_worker_data' => count($inactive_workers_data) ? $inactive_workers_data : (object)[],
                'others_data' => count($others_all_data) ? $others_all_data : (object)[],
                'area_data' => count($area_all_data) ? $area_all_data : (object)[],
                'office_data' => count($offices_all_data) ? $offices_all_data : (object)[],
                'grand_total' => count($total_column_data) ? $total_column_data : (object)[],
            ];
            
	        if (count($data)) {
	            return $this->sendResponse($data, __('record_found'));
	        }

	        return $this->sendResponse([], __('record_not_found'));
	    } 
	    catch (Exception $e) {
	        $errorMessage = $e->getMessage();
            $errorFile = $e->getFile();
            $errorLine = $e->getLine();

            // Combine the error message with its location
            $errorDetails = [
                'message' => $errorMessage,
                'file' => $errorFile,
                'line' => $errorLine,
            ];

            // Assuming sendError is a method that can accept an array of error details
            return $this->sendError(__('something_went_wrong'), $errorDetails, 400);
	    }
    }

    // TAB 3 - DATA
    private function tab3Data($request, $dateOrYear, $getDataBy = 'month') 
    {
        try {    
            $month = $year = '';
            if($getDataBy == 'month') {
                $month = $dateOrYear->format('m');
                $year = $dateOrYear->format('Y');
            }
            elseif($getDataBy == 'year') {
                $year = $dateOrYear;
            }

            $city_id = $request->input('city_id', '');
            $office_id = $request->input('office_id', '');
            $user_ids = $request->input('user_ids', '[]');
            $user_ids = is_array($user_ids) ? $user_ids : [];

            $sql = "SELECT DISTINCT 
                    u.id,
                    u.status,
                    user_offices.deleted_at AS office_deleted,
                    CONCAT(u.first_name, ' ', u.last_name) AS mc_name,
                    CONCAT(u.first_name_kana, ' ', u.last_name_kana) AS mc_name_kana,
                    offices.id AS office_id,
                    offices.name AS office_name,
                    cities.id AS city_id,
                    cities.name AS city_name,
                    teams.id AS team_id,
                    teams.name AS team_name,
                    NULLIF(SUM(report.report_count), 0) AS report_count,
                    NULLIF(COALESCE(SUM(report.deposit_amount), 0) + COALESCE(SUM(report.balance_amount), 0), 0) AS billing_amount
                FROM users AS u
                INNER JOIN user_offices ON u.id = user_offices.user_id
                INNER JOIN offices ON user_offices.office_id = offices.id
                INNER JOIN cities ON offices.city_id = cities.id
                INNER JOIN office_departments as od ON offices.id = od.office_id
                LEFT JOIN user_teams ON u.id = user_teams.user_id and user_teams.deleted_at is null
                LEFT JOIN teams ON user_teams.team_id = teams.id
                LEFT JOIN LATERAL (
                    SELECT 
                        1 AS report_count,
                        -- psoz.worker_id,
                        -- psoz.prgrs_taxoffice_shipping_date,
                        psoz.deposit_amount,
                        psoz.balance_amount
                    FROM project_sozoku AS psoz
                    INNER JOIN office_departments od ON 
                        psoz.office_departments_id = od.id 
                        -- AND od.office_id = offices.id
                    WHERE 
                        psoz.deleted_at IS NULL AND 
                        psoz.prgrs_taxoffice_shipping_date IS NOT NULL ";

            $bindings = [];
            if(!empty($year)) {
                $sql .= "AND EXTRACT(YEAR FROM psoz.prgrs_taxoffice_shipping_date) = ? ";
                $bindings[] = $year;
            }
            if(!empty($month)) {
                $sql .= "AND EXTRACT(MONTH FROM psoz.prgrs_taxoffice_shipping_date) = ? ";
                $bindings[] = $month;
            }
            
            $sql .= "AND psoz.worker_id = u.id ";
            $sql .= "AND psoz.worker_office_id = offices.id ";
            $sql .= ") AS report ON true ";
            
            $sql .= "WHERE 1=1 ";
            $sql .= "AND u.department_id = $this->department_id ";
            $sql .= "AND u.id NOT IN " . $this->js_admin_ids_raw . " ";
            $sql .= "AND u.deleted_at IS NULL ";
            $sql .= "AND offices.status = 1 ";
            $sql .= "AND od.status = 1 ";
            $sql .= "AND od.department_id = $this->department_id ";
            if(!empty($city_id)) {
                $sql .= "AND offices.city_id = ? ";
                $bindings[] = $city_id;
            }
            if(!empty($office_id)) {
                $sql .= "AND offices.id = ? ";
                $bindings[] = $office_id;
            }
            if(!empty($user_ids) && count($user_ids)) {
                $placeholders = implode(', ', array_fill(0, count($user_ids), '?'));
                $sql .= "AND u.id IN ($placeholders) ";
                $bindings = array_merge($bindings, $user_ids);
            }
            $sql .= "GROUP BY u.id, offices.id, cities.id, teams.id, user_offices.deleted_at ";
            $sql .= "ORDER BY offices.id ASC, u.id ASC";
            // echo $sql;exit;
            $data = DB::select($sql, $bindings);
            // dd($data);
    
            return $data;
        } 
        catch (Exception $e) {
            $errorMessage = $e->getMessage();
            $errorFile = $e->getFile();
            $errorLine = $e->getLine();

            // Combine the error message with its location
            $errorDetails = [
                'message' => $errorMessage,
                'file' => $errorFile,
                'line' => $errorLine,
            ];

            // Assuming sendError is a method that can accept an array of error details
            return $this->sendError(__('something_went_wrong'), $errorDetails, 400);
        }
    }

    /**
     * Current loop record - $cur_rec
     * Existing record - $ex_rec
     */
    private function tab3Array($cur_rec, $ex_rec = [])
    {
        if(count($ex_rec)) {

            $_report_count = $cur_rec->report_count + $ex_rec['count'];
            $_billing_amount = intval(str_replace(",", "", $cur_rec->billing_amount)) + intval(str_replace(",", "", $ex_rec['earning']));

            $data = [
                'count'     => $_report_count,
                'earning'   => number_format($_billing_amount, 0, '.', ','),
            ];
        }
        else {
            $data = [
                'count'     => !empty($cur_rec->report_count) ? $cur_rec->report_count : 0,
                'earning'   => number_format($cur_rec->billing_amount, 0, '.', ','),
            ];
        }

        return $data;
    }
    
    private function tab3DefaultArray()
    {
        $data = [
            'count'     => 0,
            'earning'   => 0,
        ];

        return $data;
    }

    // TAB 4
    public function tab4(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'start_date' => 'required|date_format:Y-m',
                'end_date' => 'required|date_format:Y-m',
                'is_yearly_total' => 'in:1,0',
                'is_grand_total' => 'in:1,0',
                'search_type' => 'required|in:area,office,employee',
            ]);
        
            if ($validator->fails()) {
                $errors = $validator->errors();
                $error_array = [];
                if($errors->first('start_date')) {
                    $error_array['start_date'] = [$errors->first('start_date')];
                }
                if($errors->first('end_date')) {
                    $error_array['end_date'] = [$errors->first('end_date')];
                }
                if($errors->first('is_yearly_total')) {
                    $error_array['is_yearly_total'] = [$errors->first('is_yearly_total')];
                }
                if($errors->first('is_grand_total')) {
                    $error_array['is_grand_total'] = [$errors->first('is_grand_total')];
                }
                return $this->sendError(__('something_went_wrong'), $error_array, 422);
            }

            $search_type = $request->input('search_type', '');      // Possible values area, office, employee
            $is_yearly_total = $request->input('is_yearly_total', 0);
            $is_grand_total = $request->input('is_grand_total', 0);

            // Assuming $start_date and $end_date are in 'Y-m' format, e.g., '2023-12'
            $start_date = Carbon::createFromFormat('Y-m-d', $request->start_date.'-01');
            $end_date = Carbon::createFromFormat('Y-m-d', $request->end_date.'-01')->endOfMonth(); // Ensure the end date covers the end of the month

            $currentDate = $start_date->copy();
            $previousYear = $start_date->year;

            // RESPONSE DATA CODE
            $response_data = [];            
            // Initial run for Function
            $response_data[$currentDate->year][$currentDate->format('m')] = $this->tab4Data($request, $currentDate, 'month');
            // Move to the next month for the loop start
            $currentDate->addMonth();
            
            while ($currentDate->lessThanOrEqualTo($end_date)) {
                // Check if the year has changed, indicating the start of a new year
                if ($currentDate->year != $previousYear && $is_yearly_total == 1) {
                    // Run Function for total of year calculation before processing the new year
                    $response_data[$previousYear]['year_total'] = $this->tab4Data($request, $previousYear, 'year');
                    $previousYear = $currentDate->year;
                }

                // Run Function for the current month
                $response_data[$currentDate->year][$currentDate->format('m')] = $this->tab4Data($request, $currentDate, 'month');

                // Move to the next month
                $currentDate->addMonth();
            }

            // After completing the loop, check if Function needs to be called for the end date's year
            if ($end_date->year == $previousYear && $is_yearly_total == 1) {
                $response_data[$end_date->year]['year_total'] = $this->tab4Data($request, $end_date->year, 'year');
            }
            
            // For grand total row wise
            if($is_grand_total == 1) {
                $response_data['all_years']['grand_total'] = $this->tab4Data($request, $end_date->year, 'all_years');
            }
            // dd($response_data);

            // ALL DATA
            $worker_all_data = $teams_all_data = $non_teams_all_data = $inactive_workers_data = $others_all_data = $areas_all_data = $offices_all_data = $total_column_data = [];
            foreach($response_data as $year => $record) {
                
                if(is_array($record)) {
                    foreach($record as $month => $worker_records) {

                        if(is_array($worker_records)) {
                            foreach($worker_records as $w_rec) {
                                $worker_check = $others_check = 0;
                                // ALL WORKERs DATA
                                // if($w_rec->status == 1 && $w_rec->office_deleted == null && $search_type == 'employee') {
                                if($w_rec->status == 1 && $search_type == 'employee') {
                                    $worker_check = 1;
                                    if(!isset($worker_all_data[$w_rec->id.'-'.$w_rec->office_id]) && $month != 'year_total' && $month != 'grand_total') {
                                        $worker_all_data[$w_rec->id.'-'.$w_rec->office_id] = [
                                            'Base' => $w_rec->office_name,
                                            'Team' => !empty($w_rec->team_name) ? $w_rec->team_name : '',
                                            'Worker' => $w_rec->mc_name,
                                            $this->months[$month] . ' ' . $year => $this->tab4Array($w_rec)
                                        ];
                                    }
                                    elseif(isset($worker_all_data[$w_rec->id.'-'.$w_rec->office_id]) && $month != 'year_total' && $month != 'grand_total') {
                                        $worker_all_data[$w_rec->id.'-'.$w_rec->office_id][$this->months[$month] . ' ' . $year] = $this->tab4Array($w_rec);
                                    }
                                    elseif(isset($worker_all_data[$w_rec->id.'-'.$w_rec->office_id]) && $month == 'year_total') {
                                        $worker_all_data[$w_rec->id.'-'.$w_rec->office_id][$year . ' Total'] = $this->tab4Array($w_rec);
                                    }
                                    elseif(isset($worker_all_data[$w_rec->id.'-'.$w_rec->office_id]) && $month == 'grand_total') {
                                        $worker_all_data[$w_rec->id.'-'.$w_rec->office_id]['Grand Total'] = $this->tab4Array($w_rec);
                                    }
                                }

                                /*  // ALL TEAMS DATA
                                if($w_rec->status == 1 && !empty($w_rec->team_id) && $w_rec->office_deleted == null && $search_type == 'employee') {
                                    $worker_check = 1;
                                    if(!isset($teams_all_data['team-' . $w_rec->team_id]) && $month != 'year_total' && $month != 'grand_total') {
                                        $teams_all_data['team-' . $w_rec->team_id] = [
                                            'Base' => $w_rec->office_name,
                                            'Team' => $w_rec->team_name,
                                            'Worker' => __('totalling'),
                                            $this->months[$month] . ' ' . $year => $this->tab4Array($w_rec)
                                        ];
                                    }
                                    elseif(isset($teams_all_data['team-' . $w_rec->team_id]) && isset($this->months[$month]) && !isset($teams_all_data['team-' . $w_rec->team_id][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $teams_all_data['team-' . $w_rec->team_id][$this->months[$month] . ' ' . $year] = $this->tab4Array($w_rec);
                                    }
                                    elseif(isset($teams_all_data['team-' . $w_rec->team_id]) && !isset($teams_all_data['team-' . $w_rec->team_id][$year . ' Total']) && $month == 'year_total') {
                                        $teams_all_data['team-' . $w_rec->team_id][$year . ' Total'] = $this->tab4Array($w_rec);
                                    }
                                    elseif(isset($teams_all_data['team-' . $w_rec->team_id]) && !isset($teams_all_data['team-' . $w_rec->team_id]['Grand Total']) && $month == 'grand_total') {
                                        $teams_all_data['team-' . $w_rec->team_id]['Grand Total'] = $this->tab4Array($w_rec);
                                    }
                                    elseif(isset($teams_all_data['team-' . $w_rec->team_id]) && isset($this->months[$month]) && isset($teams_all_data['team-' . $w_rec->team_id][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $teams_all_data['team-' . $w_rec->team_id][$this->months[$month] . ' ' . $year];
                                        $teams_all_data['team-' . $w_rec->team_id][$this->months[$month] . ' ' . $year] = $this->tab4Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($teams_all_data['team-' . $w_rec->team_id]) && isset($teams_all_data['team-' . $w_rec->team_id][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $teams_all_data['team-' . $w_rec->team_id][$year . ' Total'];
                                        $teams_all_data['team-' . $w_rec->team_id][$year . ' Total'] = $this->tab4Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($teams_all_data['team-' . $w_rec->team_id]) && isset($teams_all_data['team-' . $w_rec->team_id]['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $teams_all_data['team-' . $w_rec->team_id]['Grand Total'];
                                        $teams_all_data['team-' . $w_rec->team_id]['Grand Total'] = $this->tab4Array($w_rec, $existing_array);
                                    }
                                }

                                // ALL NON-TEAMS DATA
                                if($w_rec->status == 1 && empty($w_rec->team_id) && $w_rec->office_deleted == null && $search_type == 'employee') {
                                    $worker_check = 1;
                                    if(!isset($non_teams_all_data['non-team-office-' . $w_rec->office_id]) && $month != 'year_total' && $month != 'grand_total') {
                                        $non_teams_all_data['non-team-office-' . $w_rec->office_id] = [
                                            'Base' => $w_rec->office_name,
                                            'Team' => __('not_a_team_member'),
                                            'Worker' => __('totalling'),
                                            $this->months[$month] . ' ' . $year => $this->tab4Array($w_rec)
                                        ];
                                    }
                                    elseif(isset($non_teams_all_data['non-team-office-' . $w_rec->office_id]) && isset($this->months[$month]) && !isset($non_teams_all_data['non-team-office-' . $w_rec->office_id][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $non_teams_all_data['non-team-office-' . $w_rec->office_id][$this->months[$month] . ' ' . $year] = $this->tab4Array($w_rec);
                                    }
                                    elseif(isset($non_teams_all_data['non-team-office-' . $w_rec->office_id]) && !isset($non_teams_all_data['non-team-office-' . $w_rec->office_id][$year . ' Total']) && $month == 'year_total') {
                                        $non_teams_all_data['non-team-office-' . $w_rec->office_id][$year . ' Total'] = $this->tab4Array($w_rec);
                                    }
                                    elseif(isset($non_teams_all_data['non-team-office-' . $w_rec->office_id]) && !isset($non_teams_all_data['non-team-office-' . $w_rec->office_id]['Grand Total']) && $month == 'grand_total') {
                                        $non_teams_all_data['non-team-office-' . $w_rec->office_id]['Grand Total'] = $this->tab4Array($w_rec);
                                    }
                                    elseif(isset($non_teams_all_data['non-team-office-' . $w_rec->office_id]) && isset($this->months[$month]) && isset($non_teams_all_data['non-team-office-' . $w_rec->office_id][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $non_teams_all_data['non-team-office-' . $w_rec->office_id][$this->months[$month] . ' ' . $year];
                                        $non_teams_all_data['non-team-office-' . $w_rec->office_id][$this->months[$month] . ' ' . $year] = $this->tab4Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($non_teams_all_data['non-team-office-' . $w_rec->office_id]) && isset($non_teams_all_data['non-team-office-' . $w_rec->office_id][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $non_teams_all_data['non-team-office-' . $w_rec->office_id][$year . ' Total'];
                                        $non_teams_all_data['non-team-office-' . $w_rec->office_id][$year . ' Total'] = $this->tab4Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($non_teams_all_data['non-team-office-' . $w_rec->office_id]) && isset($non_teams_all_data['non-team-office-' . $w_rec->office_id]['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $non_teams_all_data['non-team-office-' . $w_rec->office_id]['Grand Total'];
                                        $non_teams_all_data['non-team-office-' . $w_rec->office_id]['Grand Total'] = $this->tab4Array($w_rec, $existing_array);
                                    }
                                } */

                                // ALL INACTIVE WORKERs DATA
                                /* if($w_rec->status == 0 && $search_type == 'employee') {
                                    $worker_check = 1;
                                    if(!isset($inactive_workers_data['inactive-workers-' . $w_rec->office_id]) && $month != 'year_total' && $month != 'grand_total') {
                                        $inactive_workers_data['inactive-workers-' . $w_rec->office_id] = [
                                            'Base' => $w_rec->office_name,
                                            'Team' => __('others'),
                                            'Worker' => __('totalling'),
                                            $this->months[$month] . ' ' . $year => $this->tab4Array($w_rec)
                                        ];
                                    }
                                    elseif(isset($inactive_workers_data['inactive-workers-' . $w_rec->office_id]) && isset($this->months[$month]) && !isset($inactive_workers_data['inactive-workers-' . $w_rec->office_id][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $inactive_workers_data['inactive-workers-' . $w_rec->office_id][$this->months[$month] . ' ' . $year] = $this->tab4Array($w_rec);
                                    }
                                    elseif(isset($inactive_workers_data['inactive-workers-' . $w_rec->office_id]) && !isset($inactive_workers_data['inactive-workers-' . $w_rec->office_id][$year . ' Total']) && $month == 'year_total') {
                                        $inactive_workers_data['inactive-workers-' . $w_rec->office_id][$year . ' Total'] = $this->tab4Array($w_rec);
                                    }
                                    elseif(isset($inactive_workers_data['inactive-workers-' . $w_rec->office_id]) && !isset($inactive_workers_data['inactive-workers-' . $w_rec->office_id]['Grand Total']) && $month == 'grand_total') {
                                        $inactive_workers_data['inactive-workers-' . $w_rec->office_id]['Grand Total'] = $this->tab4Array($w_rec);
                                    }
                                    elseif(isset($inactive_workers_data['inactive-workers-' . $w_rec->office_id]) && isset($this->months[$month]) && isset($inactive_workers_data['inactive-workers-' . $w_rec->office_id][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $inactive_workers_data['inactive-workers-' . $w_rec->office_id][$this->months[$month] . ' ' . $year];
                                        $inactive_workers_data['inactive-workers-' . $w_rec->office_id][$this->months[$month] . ' ' . $year] = $this->tab4Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($inactive_workers_data['inactive-workers-' . $w_rec->office_id]) && isset($inactive_workers_data['inactive-workers-' . $w_rec->office_id][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $inactive_workers_data['inactive-workers-' . $w_rec->office_id][$year . ' Total'];
                                        $inactive_workers_data['inactive-workers-' . $w_rec->office_id][$year . ' Total'] = $this->tab4Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($inactive_workers_data['inactive-workers-' . $w_rec->office_id]) && isset($inactive_workers_data['inactive-workers-' . $w_rec->office_id]['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $inactive_workers_data['inactive-workers-' . $w_rec->office_id]['Grand Total'];
                                        $inactive_workers_data['inactive-workers-' . $w_rec->office_id]['Grand Total'] = $this->tab4Array($w_rec, $existing_array);
                                    }
                                } */

                                // ALL OTHERS DATA
                                // if(($w_rec->status == 0 || !empty($w_rec->office_deleted)) && $search_type == 'employee') {
                                if(($w_rec->status == 0) && $search_type == 'employee') {
                                    $others_check = 1;
                                    if(!isset($others_all_data['others']) && $month != 'year_total' && $month != 'grand_total') {
                                        $others_all_data['others'] = [
                                            'Base' => '',
                                            'Team' => '',
                                            'Worker' => __('others'),
                                            $this->months[$month] . ' ' . $year => $this->tab4Array($w_rec)
                                        ];
                                    }
                                    elseif(isset($others_all_data['others']) && isset($this->months[$month]) && !isset($others_all_data['others'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $others_all_data['others'][$this->months[$month] . ' ' . $year] = $this->tab4Array($w_rec);
                                    }
                                    elseif(isset($others_all_data['others']) && !isset($others_all_data['others'][$year . ' Total']) && $month == 'year_total') {
                                        $others_all_data['others'][$year . ' Total'] = $this->tab4Array($w_rec);
                                    }
                                    elseif(isset($others_all_data['others']) && !isset($others_all_data['others']['Grand Total']) && $month == 'grand_total') {
                                        $others_all_data['others']['Grand Total'] = $this->tab4Array($w_rec);
                                    }
                                    elseif(isset($others_all_data['others']) && isset($this->months[$month]) && isset($others_all_data['others'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $others_all_data['others'][$this->months[$month] . ' ' . $year];
                                        $others_all_data['others'][$this->months[$month] . ' ' . $year] = $this->tab4Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($others_all_data['others']) && isset($others_all_data['others'][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $others_all_data['others'][$year . ' Total'];
                                        $others_all_data['others'][$year . ' Total'] = $this->tab4Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($others_all_data['others']) && isset($others_all_data['others']['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $others_all_data['others']['Grand Total'];
                                        $others_all_data['others']['Grand Total'] = $this->tab4Array($w_rec, $existing_array);
                                    }
                                }

                                // IF THERE IS NO OTHERS DATA IN CURRENT ITERATION THEN ADD A DEFAULT DATA
                                if($worker_check == 1 && $others_check == 0) {
                                    if(!isset($others_all_data['others']) && $month != 'year_total' && $month != 'grand_total') {
                                        $others_all_data['others'] = [
                                            'Base' => '',
                                            'Team' => '',
                                            'Worker' => __('others'),
                                            $this->months[$month] . ' ' . $year => $this->tab4DefaultArray()
                                        ];
                                    }
                                    elseif(isset($others_all_data['others']) && isset($this->months[$month]) && !isset($others_all_data['others'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $others_all_data['others'][$this->months[$month] . ' ' . $year] = $this->tab4DefaultArray();
                                    }
                                    elseif(isset($others_all_data['others']) && !isset($others_all_data['others'][$year . ' Total']) && $month == 'year_total') {
                                        $others_all_data['others'][$year . ' Total'] = $this->tab4DefaultArray();
                                    }
                                    elseif(isset($others_all_data['others']) && !isset($others_all_data['others']['Grand Total']) && $month == 'grand_total') {
                                        $others_all_data['others']['Grand Total'] = $this->tab4DefaultArray();
                                    }
                                }

                                // ALL AREAS DATA
                                if($search_type == 'area') {
                                    if(!isset($areas_all_data['area-' . $w_rec->city_id]) && $month != 'year_total' && $month != 'grand_total') {
                                        $areas_all_data['area-' . $w_rec->city_id] = [
                                            'Base' => $w_rec->city_name,
                                            'Team' => 'All',
                                            'Worker' => __('total'),
                                            $this->months[$month] . ' ' . $year => $this->tab4Array($w_rec)
                                        ];
                                    }
                                    elseif(isset($areas_all_data['area-' . $w_rec->city_id]) && isset($this->months[$month]) && !isset($areas_all_data['area-' . $w_rec->city_id][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $areas_all_data['area-' . $w_rec->city_id][$this->months[$month] . ' ' . $year] = $this->tab4Array($w_rec);
                                    }
                                    elseif(isset($areas_all_data['area-' . $w_rec->city_id]) && !isset($areas_all_data['area-' . $w_rec->city_id][$year . ' Total']) && $month == 'year_total') {
                                        $areas_all_data['area-' . $w_rec->city_id][$year . ' Total'] = $this->tab4Array($w_rec);
                                    }
                                    elseif(isset($areas_all_data['area-' . $w_rec->city_id]) && !isset($areas_all_data['area-' . $w_rec->city_id]['Grand Total']) && $month == 'grand_total') {
                                        $areas_all_data['area-' . $w_rec->city_id]['Grand Total'] = $this->tab4Array($w_rec);
                                    }
                                    elseif(isset($areas_all_data['area-' . $w_rec->city_id]) && isset($this->months[$month]) && isset($areas_all_data['area-' . $w_rec->city_id][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $areas_all_data['area-' . $w_rec->city_id][$this->months[$month] . ' ' . $year];
                                        $areas_all_data['area-' . $w_rec->city_id][$this->months[$month] . ' ' . $year] = $this->tab4Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($areas_all_data['area-' . $w_rec->city_id]) && isset($areas_all_data['area-' . $w_rec->city_id][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $areas_all_data['area-' . $w_rec->city_id][$year . ' Total'];
                                        $areas_all_data['area-' . $w_rec->city_id][$year . ' Total'] = $this->tab4Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($areas_all_data['area-' . $w_rec->city_id]) && isset($areas_all_data['area-' . $w_rec->city_id]['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $areas_all_data['area-' . $w_rec->city_id]['Grand Total'];
                                        $areas_all_data['area-' . $w_rec->city_id]['Grand Total'] = $this->tab4Array($w_rec, $existing_array);
                                    }
                                }

                                // ALL OFFICES DATA
                                if($search_type == 'office') {
                                    if(!isset($offices_all_data['office-' . $w_rec->office_id]) && $month != 'year_total' && $month != 'grand_total') {
                                        $offices_all_data['office-' . $w_rec->office_id] = [
                                            'Base' => $w_rec->office_name,
                                            'Team' => 'All',
                                            'Worker' => __('total'),
                                            $this->months[$month] . ' ' . $year => $this->tab4Array($w_rec)
                                        ];
                                    }
                                    elseif(isset($offices_all_data['office-' . $w_rec->office_id]) && isset($this->months[$month]) && !isset($offices_all_data['office-' . $w_rec->office_id][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $offices_all_data['office-' . $w_rec->office_id][$this->months[$month] . ' ' . $year] = $this->tab4Array($w_rec);
                                    }
                                    elseif(isset($offices_all_data['office-' . $w_rec->office_id]) && !isset($offices_all_data['office-' . $w_rec->office_id][$year . ' Total']) && $month == 'year_total') {
                                        $offices_all_data['office-' . $w_rec->office_id][$year . ' Total'] = $this->tab4Array($w_rec);
                                    }
                                    elseif(isset($offices_all_data['office-' . $w_rec->office_id]) && !isset($offices_all_data['office-' . $w_rec->office_id]['Grand Total']) && $month == 'grand_total') {
                                        $offices_all_data['office-' . $w_rec->office_id]['Grand Total'] = $this->tab4Array($w_rec);
                                    }
                                    elseif(isset($offices_all_data['office-' . $w_rec->office_id]) && isset($this->months[$month]) && isset($offices_all_data['office-' . $w_rec->office_id][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $offices_all_data['office-' . $w_rec->office_id][$this->months[$month] . ' ' . $year];
                                        $offices_all_data['office-' . $w_rec->office_id][$this->months[$month] . ' ' . $year] = $this->tab4Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($offices_all_data['office-' . $w_rec->office_id]) && isset($offices_all_data['office-' . $w_rec->office_id][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $offices_all_data['office-' . $w_rec->office_id][$year . ' Total'];
                                        $offices_all_data['office-' . $w_rec->office_id][$year . ' Total'] = $this->tab4Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($offices_all_data['office-' . $w_rec->office_id]) && isset($offices_all_data['office-' . $w_rec->office_id]['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $offices_all_data['office-' . $w_rec->office_id]['Grand Total'];
                                        $offices_all_data['office-' . $w_rec->office_id]['Grand Total'] = $this->tab4Array($w_rec, $existing_array);
                                    }
                                }

                                // ALL TOTAL DATA
                                if(!isset($total_column_data['column_wise_total']) && $month != 'year_total' && $month != 'grand_total') {
                                    $total_column_data['column_wise_total'] = [
                                        'Base' => __('grand_total'),
                                        'Team' => '',
                                        'Worker' => '',
                                        $this->months[$month] . ' ' . $year => $this->tab4Array($w_rec)
                                    ];
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($this->months[$month]) && !isset($total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                    $total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year] = $this->tab4Array($w_rec);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && !isset($total_column_data['column_wise_total'][$year . ' Total']) && $month == 'year_total') {
                                    $total_column_data['column_wise_total'][$year . ' Total'] = $this->tab4Array($w_rec);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && !isset($total_column_data['column_wise_total']['Grand Total']) && $month == 'grand_total') {
                                    $total_column_data['column_wise_total']['Grand Total'] = $this->tab4Array($w_rec);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($this->months[$month]) && isset($total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                    $existing_array = $total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year];
                                    $total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year] = $this->tab4Array($w_rec, $existing_array);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($total_column_data['column_wise_total'][$year . ' Total']) && $month == 'year_total') {
                                    $existing_array = $total_column_data['column_wise_total'][$year . ' Total'];
                                    $total_column_data['column_wise_total'][$year . ' Total'] = $this->tab4Array($w_rec, $existing_array);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($total_column_data['column_wise_total']['Grand Total']) && $month == 'grand_total') {
                                    $existing_array = $total_column_data['column_wise_total']['Grand Total'];
                                    $total_column_data['column_wise_total']['Grand Total'] = $this->tab4Array($w_rec, $existing_array);
                                }
                            }
                        }
                    }
                }
            }
            // dd($worker_all_data, $teams_all_data);

            $data = [
                'search_type' => $search_type,
                'worker_data' => count($worker_all_data) ? $worker_all_data : (object)[],
                'teams_data' => count($teams_all_data) ? $teams_all_data : (object)[],
                'non_teams_data' => count($non_teams_all_data) ? $non_teams_all_data : (object)[],
                'inactive_worker_data' => count($inactive_workers_data) ? $inactive_workers_data : (object)[],
                'others_data' => count($others_all_data) ? $others_all_data : (object)[],
                'area_data' => count($areas_all_data) ? $areas_all_data : (object)[],
                'office_data' => count($offices_all_data) ? $offices_all_data : (object)[],
                'grand_total' => count($total_column_data) ? $total_column_data : (object)[],
            ];
            
	        if (count($data)) {
	            return $this->sendResponse($data, __('record_found'));
	        }

	        return $this->sendResponse([], __('record_not_found'));
	    } 
	    catch (Exception $e) {
	        $errorMessage = $e->getMessage();
            $errorFile = $e->getFile();
            $errorLine = $e->getLine();

            // Combine the error message with its location
            $errorDetails = [
                'message' => $errorMessage,
                'file' => $errorFile,
                'line' => $errorLine,
            ];

            // Assuming sendError is a method that can accept an array of error details
            return $this->sendError(__('something_went_wrong'), $errorDetails, 400);
	    }
    }

    // private function tab4Data($request, $dateOrYear, $getDataBy = 'month') 
    // {
    //     try {    
    //         $month = $year = '';
    //         if($getDataBy == 'month') {
    //             $month = $dateOrYear->format('m');
    //             $year = $dateOrYear->format('Y');
    //         }
    //         elseif($getDataBy == 'year') {
    //             $year = $dateOrYear;
    //         }

    //         $city_id = $request->input('city_id', '');
    //         $office_id = $request->input('office_id', '');
    //         $user_ids = $request->input('user_ids', '[]');
    //         $user_ids = is_array($user_ids) ? $user_ids : [];
    
    //         // QUERY FOR checker1_id AND prgrs_verify_1st_comp_date
    //         $yearConditionPsozSelect1 = !empty($year) ? "AND EXTRACT(YEAR FROM report.prgrs_verify_1st_comp_date) = '" . $year . "'" : "";
    //         $monthConditionPsozSelect1 = !empty($month) ? "AND EXTRACT(MONTH FROM report.prgrs_verify_1st_comp_date) = '" . $month . "' " : "";
            
    //         $yearConditionPsozSelect2 = !empty($year) ? "AND EXTRACT(YEAR FROM report.prgrs_verify_2nd_comp_date) = '" . $year . "'" : "";
    //         $monthConditionPsozSelect2 = !empty($month) ? "AND EXTRACT(MONTH FROM report.prgrs_verify_2nd_comp_date) = '" . $month . "' " : "";
           
    //         $yearConditionPsozSelect3 = !empty($year) ? "AND EXTRACT(YEAR FROM report.prgrs_verify_3rd_comp_date) = '" . $year . "'" : "";
    //         $monthConditionPsozSelect3 = !empty($month) ? "AND EXTRACT(MONTH FROM report.prgrs_verify_3rd_comp_date) = '" . $month . "' " : "";
            
    //         $yearConditionPsozSelect4 = !empty($year) ? "AND EXTRACT(YEAR FROM report.prgrs_final_check_1st_comp_date) = '" . $year . "'" : "";
    //         $monthConditionPsozSelect4 = !empty($month) ? "AND EXTRACT(MONTH FROM report.prgrs_final_check_1st_comp_date) = '" . $month . "' " : "";
            
    //         $yearConditionPsozSelect5 = !empty($year) ? "AND EXTRACT(YEAR FROM report.prgrs_final_check_2nd_comp_date) = '" . $year . "'" : "";
    //         $monthConditionPsozSelect5 = !empty($month) ? "AND EXTRACT(MONTH FROM report.prgrs_final_check_2nd_comp_date) = '" . $month . "' " : "";

    //         $bindings = [];
    //         $sql = "
    //             SELECT
    //                 distinct 
    //                 u.id,
    //                 u.status,
    //                 user_offices.deleted_at as office_deleted,
    //                 CONCAT(u.first_name,' ',u.last_name) as mc_name,
    //                 offices.id as office_id,
    //                 offices.name as office_name,
    //                 cities.id as city_id,
    //                 cities.name as city_name,
    //                 -- teams.id as team_id,
    //                 -- teams.name as team_name,
    //                 COALESCE(SUM(case when report.checker1_id = u.id ".$yearConditionPsozSelect1."  ".$monthConditionPsozSelect1." then 1 else 0 end),
    //                 0) as accounts_1,
    //                 COALESCE(SUM(case when report.checker2_id = u.id ".$yearConditionPsozSelect2."  ".$monthConditionPsozSelect2." then 1 else 0 end),
    //                 0) as accounts_2,
    //                 COALESCE(SUM(case when report.checker3_id = u.id ".$yearConditionPsozSelect3."  ".$monthConditionPsozSelect3." then 1 else 0 end),
    //                 0) as accounts_3,
    //                 COALESCE(SUM(case when report.final1_id = u.id ".$yearConditionPsozSelect4."  ".$monthConditionPsozSelect4." then 1 else 0 end),
    //                 0) as final_1,
    //                 COALESCE(SUM(case when report.final2_id = u.id ".$yearConditionPsozSelect5."  ".$monthConditionPsozSelect5." then 1 else 0 end),
    //                 0) as final_2
    //             FROM
    //                 users as u
    //             INNER JOIN user_offices on
    //                 u.id = user_offices.user_id 
    //                 -- and user_offices.deleted_at is null
    //             INNER JOIN offices on
    //                 user_offices.office_id = offices.id
    //             INNER JOIN cities on
    //                 offices.city_id = cities.id
    //             INNER JOIN office_departments as od on
    //                 offices.id = od.office_id
    //             -- LEFT JOIN user_teams on
    //             --     u.id = user_teams.user_id and user_teams.deleted_at is null
    //             -- LEFT JOIN teams on
    //             --     user_teams.team_id = teams.id
    //             LEFT JOIN LATERAL (
    //                 select
    //                     psoz.checker1_id,
    //                     psoz.checker2_id,
    //                     psoz.checker3_id,
    //                     psoz.final1_id,
    //                     psoz.final2_id,
    //                     psoz.prgrs_verify_1st_comp_date,
    //                     psoz.prgrs_verify_2nd_comp_date,
    //                     psoz.prgrs_verify_3rd_comp_date,
    //                     psoz.prgrs_final_check_1st_comp_date,
    //                     psoz.prgrs_final_check_2nd_comp_date
    //                 from
    //                     project_sozoku as psoz
    //                 inner join 
    //                     office_departments od1 on psoz.office_departments_id = od1.id 
    //                 where
    //                     psoz.deleted_at is null
    //                     and (psoz.checker1_id = u.id or psoz.checker2_id = u.id or psoz.checker3_id = u.id or psoz.final1_id = u.id or psoz.final2_id = u.id)
    //                     and (
    //                         psoz.checker1_office_id = offices.id OR
    //                         psoz.checker2_office_id = offices.id OR
    //                         psoz.checker3_office_id = offices.id OR
    //                         psoz.final1_office_id = offices.id OR
    //                         psoz.final2_office_id = offices.id
    //                     )
    //             ) as report on true
    //             WHERE
    //                 u.department_id = ".$this->department_id." 
    //                 and u.id NOT IN " . $this->js_admin_ids_raw . " 
    //                 and u.deleted_at is null 
    //                 and offices.status = 1  
    //                 and od.status = 1 
    //                 and od.department_id = ".$this->department_id." ";  
            
    //         if(!empty($city_id)) {
    //             $sql .= "AND offices.city_id = ? ";
    //             $bindings[] = $city_id;
    //         }
    //         if(!empty($office_id)) {
    //             $sql .= "AND offices.id = ? ";
    //             $bindings[] = $office_id;
    //         }
    //         if(!empty($user_ids) && count($user_ids)) {
    //             $placeholders = implode(', ', array_fill(0, count($user_ids), '?'));
    //             $sql .= "AND u.id IN ($placeholders) ";
    //             $bindings = array_merge($bindings, $user_ids);
    //         }
                    
    //         $sql .= "GROUP BY
    //                 u.id,
    //                 offices.id,
    //                 cities.id,
    //                 -- teams.id,
    //                 user_offices.deleted_at
    //             ORDER BY
    //                 offices.id asc,
    //                 u.id asc";
            
    //         // echo $sql;exit;
    //         $data1 = DB::select($sql, $bindings);

    //         /* ************************************************************************* */

    //         $yearConditionPsozWhere1 = !empty($year) ? "AND EXTRACT(YEAR FROM psoz.prgrs_verify_1st_comp_date) = '" . $year . "'" : "";
    //         $monthConditionPsozWhere1 = !empty($month) ? "AND EXTRACT(MONTH FROM psoz.prgrs_verify_1st_comp_date) = '" . $month . "' " : "";
            
    //         $yearConditionPsozWhere2 = !empty($year) ? "AND EXTRACT(YEAR FROM psoz.prgrs_verify_2nd_comp_date) = '" . $year . "'" : "";
    //         $monthConditionPsozWhere2 = !empty($month) ? "AND EXTRACT(MONTH FROM psoz.prgrs_verify_2nd_comp_date) = '" . $month . "' " : "";
           
    //         $yearConditionPsozWhere3 = !empty($year) ? "AND EXTRACT(YEAR FROM psoz.prgrs_verify_3rd_comp_date) = '" . $year . "'" : "";
    //         $monthConditionPsozWhere3 = !empty($month) ? "AND EXTRACT(MONTH FROM psoz.prgrs_verify_3rd_comp_date) = '" . $month . "' " : "";

    //         $bindings = [];
    //         $sql = "
    //             SELECT
    //                 distinct 
    //                 u.id,
    //                 u.status,
    //                 user_offices.deleted_at as office_deleted,
    //                 CONCAT(u.first_name,' ',u.last_name) as mc_name,
    //                 offices.id as office_id,
    //                 offices.name as office_name,
    //                 cities.id as city_id,
    //                 cities.name as city_name,
    //                 -- teams.id as team_id,
    //                 -- teams.name as team_name,
    //                 coalesce(SUM(report.total_amount),	0) as total_amount
    //             FROM
    //                 users as u
    //             INNER JOIN user_offices on
    //                 u.id = user_offices.user_id 
    //                 -- and user_offices.deleted_at is null
    //             INNER JOIN offices on
    //                 user_offices.office_id = offices.id
    //             INNER JOIN cities on
    //                 offices.city_id = cities.id
    //             INNER JOIN office_departments as od on
    //                 offices.id = od.office_id
    //             -- LEFT JOIN user_teams on
    //             --     u.id = user_teams.user_id and user_teams.deleted_at is null
    //             -- LEFT JOIN teams on
    //             --     user_teams.team_id = teams.id
    //             LEFT JOIN LATERAL (
    //                 select
    //                     psoz.checker1_id,
    //                     psoz.checker2_id,
    //                     psoz.checker3_id,
    //                     psoz.prgrs_verify_1st_comp_date,
    //                     psoz.prgrs_verify_2nd_comp_date,
    //                     psoz.prgrs_verify_3rd_comp_date,
    //                     COALESCE(psoz.deposit_amount, 0) + COALESCE(psoz.balance_amount, 0) AS total_amount
    //                 from
    //                     project_sozoku as psoz
    //                 inner join 
    //                     office_departments od1 on psoz.office_departments_id = od1.id
    //                 where
    //                     psoz.deleted_at is null
    //                     and (
    //                         (psoz.checker1_id = u.id ".$yearConditionPsozWhere1."  ".$monthConditionPsozWhere1." ) OR 
    //                         (psoz.checker2_id = u.id ".$yearConditionPsozWhere2."  ".$monthConditionPsozWhere2." ) OR 
    //                         (psoz.checker3_id = u.id ".$yearConditionPsozWhere3."  ".$monthConditionPsozWhere3." )
    //                     )
    //                     and (psoz.checker1_id = u.id or psoz.checker2_id = u.id or psoz.checker3_id = u.id)
    //                     and (
    //                         psoz.checker1_office_id = offices.id OR
    //                         psoz.checker2_office_id = offices.id OR
    //                         psoz.checker3_office_id = offices.id OR
    //                         psoz.final1_office_id = offices.id OR
    //                         psoz.final2_office_id = offices.id
    //                     )
    //             ) as report on true
    //             WHERE
    //                 u.department_id = ".$this->department_id." 
    //                 and u.id NOT IN " . $this->js_admin_ids_raw . " 
    //                 and u.deleted_at is null 
    //                 and offices.status = 1  
    //                 and od.status = 1 
    //                 and od.department_id = ".$this->department_id." ";  
            
    //         if(!empty($city_id)) {
    //             $sql .= "AND offices.city_id = ? ";
    //             $bindings[] = $city_id;
    //         }
    //         if(!empty($office_id)) {
    //             $sql .= "AND offices.id = ? ";
    //             $bindings[] = $office_id;
    //         }
    //         if(!empty($user_ids) && count($user_ids)) {
    //             $placeholders = implode(', ', array_fill(0, count($user_ids), '?'));
    //             $sql .= "AND u.id IN ($placeholders) ";
    //             $bindings = array_merge($bindings, $user_ids);
    //         }
                    
    //         $sql .= "GROUP BY
    //                 u.id,
    //                 offices.id,
    //                 cities.id,
    //                 -- teams.id,
    //                 user_offices.deleted_at
    //             ORDER BY
    //                 offices.id asc,
    //                 u.id asc";
            
    //         // echo $sql;exit;
    //         $data2 = DB::select($sql, $bindings);

    //         $final_data = [];
    //         $i = 0;
    //         foreach($data1 as $k => $d1) {
    //             $final_data[$i] = $d1;
    //             if(isset($data2[$k]) && $data2[$k]->id == $d1->id ) {
    //                 $final_data[$i]->total_amount = $data2[$k]->total_amount;
    //             }
    //             else {
    //                 $final_data[$i]->total_amount = 0;
    //             }
    //             $i++;
    //         }

    //         // dd($data1, $data2, $final_data);
    //         return $final_data;
    //     } 
    //     catch (Exception $e) {
    //         $errorMessage = $e->getMessage();
    //         $errorFile = $e->getFile();
    //         $errorLine = $e->getLine();

    //         // Combine the error message with its location
    //         $errorDetails = [
    //             'message' => $errorMessage,
    //             'file' => $errorFile,
    //             'line' => $errorLine,
    //         ];

    //         // Assuming sendError is a method that can accept an array of error details
    //         return $this->sendError(__('something_went_wrong'), $errorDetails, 400);
    //     }
    // }

    private function tab4Data($request, $dateOrYear, $getDataBy = 'month') 
    {
        try {    
            $month = $year = '';
            if($getDataBy == 'month') {
                $month = $dateOrYear->format('m');
                $year = $dateOrYear->format('Y');
            }
            elseif($getDataBy == 'year') {
                $year = $dateOrYear;
            }

            $city_id = $request->input('city_id', '');
            $office_id = $request->input('office_id', '');
            $user_ids = $request->input('user_ids', '[]');
            $user_ids = is_array($user_ids) ? $user_ids : [];
    
            /* ************************************ CHECKER 1 CODE ************************************* */
            // QUERY FOR checker1_id AND prgrs_verify_1st_comp_date
            $yearConditionPsozSelect1 = !empty($year) ? "AND EXTRACT(YEAR FROM report.prgrs_verify_1st_comp_date) = '" . $year . "'" : "";
            $monthConditionPsozSelect1 = !empty($month) ? "AND EXTRACT(MONTH FROM report.prgrs_verify_1st_comp_date) = '" . $month . "' " : "";
            $NotNullConditionPsozSelect1 = ($getDataBy == 'all_years') ? "AND report.prgrs_verify_1st_comp_date IS NOT NULL " : "";

            $bindings = [];
            $sql = "
                SELECT
                    distinct 
                    u.id,
                    u.status,
                    user_offices.deleted_at as office_deleted,
                    CONCAT(u.first_name,' ',u.last_name) as mc_name,
                    offices.id as office_id,
                    offices.name as office_name,
                    cities.id as city_id,
                    cities.name as city_name,
                    COALESCE(SUM(case when report.checker1_id = u.id ".$yearConditionPsozSelect1." ".$monthConditionPsozSelect1." ".$NotNullConditionPsozSelect1." then 1 else 0 end), 0) as accounts_1
                FROM
                    users as u
                INNER JOIN user_offices on
                    u.id = user_offices.user_id 
                INNER JOIN offices on
                    user_offices.office_id = offices.id
                INNER JOIN cities on
                    offices.city_id = cities.id
                INNER JOIN office_departments as od on
                    offices.id = od.office_id
                LEFT JOIN LATERAL (
                    select
                        psoz.checker1_id,
                        psoz.prgrs_verify_1st_comp_date
                    from
                        project_sozoku as psoz
                    inner join 
                        office_departments od1 on psoz.office_departments_id = od1.id 
                    where
                        psoz.deleted_at is null
                        and psoz.checker1_id = u.id
                        and psoz.checker1_office_id = offices.id
                ) as report on true
                WHERE
                    u.department_id = ".$this->department_id." 
                    and u.id NOT IN " . $this->js_admin_ids_raw . "  
                    and u.deleted_at is null 
                    and offices.status = 1  
                    and od.status = 1 
                    and od.department_id = ".$this->department_id." ";  
            
            if(!empty($city_id)) {
                $sql .= "AND offices.city_id = ? ";
                $bindings[] = $city_id;
            }
            if(!empty($office_id)) {
                $sql .= "AND offices.id = ? ";
                $bindings[] = $office_id;
            }
            if(!empty($user_ids) && count($user_ids)) {
                $placeholders = implode(', ', array_fill(0, count($user_ids), '?'));
                $sql .= "AND u.id IN ($placeholders) ";
                $bindings = array_merge($bindings, $user_ids);
            }
                    
            $sql .= "GROUP BY
                    u.id,
                    offices.id,
                    cities.id,
                    user_offices.deleted_at
                ORDER BY
                    offices.id asc,
                    u.id asc";
            
            // echo $sql;exit;
            $checker1_data = DB::select($sql, $bindings);

            /* ************************************ CHECKER 1 CODE - END ************************************* */
            
            /* ************************************ CHECKER 2 CODE ************************************* */
            $yearConditionPsozSelect2 = !empty($year) ? "AND EXTRACT(YEAR FROM report.prgrs_verify_2nd_comp_date) = '" . $year . "'" : "";
            $monthConditionPsozSelect2 = !empty($month) ? "AND EXTRACT(MONTH FROM report.prgrs_verify_2nd_comp_date) = '" . $month . "' " : "";
            $NotNullConditionPsozSelect2 = ($getDataBy == 'all_years') ? "AND report.prgrs_verify_2nd_comp_date IS NOT NULL " : "";
            
            $bindings = [];
            $sql = "
                SELECT
                    distinct 
                    u.id,
                    u.status,
                    user_offices.deleted_at as office_deleted,
                    CONCAT(u.first_name,' ',u.last_name) as mc_name,
                    offices.id as office_id,
                    offices.name as office_name,
                    cities.id as city_id,
                    cities.name as city_name,
                    COALESCE(SUM(case when report.checker2_id = u.id ".$yearConditionPsozSelect2."  ".$monthConditionPsozSelect2." ".$NotNullConditionPsozSelect2." then 1 else 0 end), 0) as accounts_2
                FROM
                    users as u
                INNER JOIN user_offices on
                    u.id = user_offices.user_id 
                INNER JOIN offices on
                    user_offices.office_id = offices.id
                INNER JOIN cities on
                    offices.city_id = cities.id
                INNER JOIN office_departments as od on
                    offices.id = od.office_id
                LEFT JOIN LATERAL (
                    select
                        psoz.checker2_id,
                        psoz.prgrs_verify_2nd_comp_date
                    from
                        project_sozoku as psoz
                    inner join 
                        office_departments od1 on psoz.office_departments_id = od1.id
                    where
                        psoz.deleted_at is null
                        and psoz.checker2_id = u.id
                        and psoz.checker2_office_id = offices.id
                ) as report on true
                WHERE
                    u.department_id = ".$this->department_id." 
                    and u.id NOT IN " . $this->js_admin_ids_raw . "  
                    and u.deleted_at is null 
                    and offices.status = 1  
                    and od.status = 1 
                    and od.department_id = ".$this->department_id." ";  
            
            if(!empty($city_id)) {
                $sql .= "AND offices.city_id = ? ";
                $bindings[] = $city_id;
            }
            if(!empty($office_id)) {
                $sql .= "AND offices.id = ? ";
                $bindings[] = $office_id;
            }
            if(!empty($user_ids) && count($user_ids)) {
                $placeholders = implode(', ', array_fill(0, count($user_ids), '?'));
                $sql .= "AND u.id IN ($placeholders) ";
                $bindings = array_merge($bindings, $user_ids);
            }
                    
            $sql .= "GROUP BY
                    u.id,
                    offices.id,
                    cities.id,
                    user_offices.deleted_at
                ORDER BY
                    offices.id asc,
                    u.id asc";
            
            // echo $sql;exit;
            $checker2_data = DB::select($sql, $bindings);
            /* ************************************ CHECKER 2 CODE - END ************************************* */
            
            /* ************************************ CHECKER 3 CODE ************************************* */
            $yearConditionPsozSelect3 = !empty($year) ? "AND EXTRACT(YEAR FROM report.prgrs_verify_3rd_comp_date) = '" . $year . "'" : "";
            $monthConditionPsozSelect3 = !empty($month) ? "AND EXTRACT(MONTH FROM report.prgrs_verify_3rd_comp_date) = '" . $month . "' " : "";
            $NotNullConditionPsozSelect3 = ($getDataBy == 'all_years') ? "AND report.prgrs_verify_3rd_comp_date IS NOT NULL " : "";

            $bindings = [];
            $sql = "
                SELECT
                    distinct 
                    u.id,
                    u.status,
                    user_offices.deleted_at as office_deleted,
                    CONCAT(u.first_name,' ',u.last_name) as mc_name,
                    offices.id as office_id,
                    offices.name as office_name,
                    cities.id as city_id,
                    cities.name as city_name,
                    COALESCE(SUM(case when report.checker3_id = u.id ".$yearConditionPsozSelect3."  ".$monthConditionPsozSelect3." ".$NotNullConditionPsozSelect3." then 1 else 0 end), 0) as accounts_3
                FROM
                    users as u
                INNER JOIN user_offices on
                    u.id = user_offices.user_id 
                INNER JOIN offices on
                    user_offices.office_id = offices.id
                INNER JOIN cities on
                    offices.city_id = cities.id
                INNER JOIN office_departments as od on
                    offices.id = od.office_id
                LEFT JOIN LATERAL (
                    select
                        psoz.checker3_id,
                        psoz.prgrs_verify_3rd_comp_date
                    from
                        project_sozoku as psoz
                    inner join 
                        office_departments od1 on psoz.office_departments_id = od1.id
                    where
                        psoz.deleted_at is null
                        and psoz.checker3_id = u.id
                        and psoz.checker3_office_id = offices.id
                ) as report on true
                WHERE
                    u.department_id = ".$this->department_id." 
                    and u.id NOT IN " . $this->js_admin_ids_raw . " 
                    and u.deleted_at is null 
                    and offices.status = 1  
                    and od.status = 1 
                    and od.department_id = ".$this->department_id." ";  
            
            if(!empty($city_id)) {
                $sql .= "AND offices.city_id = ? ";
                $bindings[] = $city_id;
            }
            if(!empty($office_id)) {
                $sql .= "AND offices.id = ? ";
                $bindings[] = $office_id;
            }
            if(!empty($user_ids) && count($user_ids)) {
                $placeholders = implode(', ', array_fill(0, count($user_ids), '?'));
                $sql .= "AND u.id IN ($placeholders) ";
                $bindings = array_merge($bindings, $user_ids);
            }
                    
            $sql .= "GROUP BY
                    u.id,
                    offices.id,
                    cities.id,
                    user_offices.deleted_at
                ORDER BY
                    offices.id asc,
                    u.id asc";
            
            // echo $sql;exit;
            $checker3_data = DB::select($sql, $bindings);
            /* ************************************ CHECKER 3 CODE - END ************************************* */
            
            /* ************************************ FINAL 1 CODE  ************************************* */
            $yearConditionPsozSelect4 = !empty($year) ? "AND EXTRACT(YEAR FROM report.prgrs_final_check_1st_comp_date) = '" . $year . "'" : "";
            $monthConditionPsozSelect4 = !empty($month) ? "AND EXTRACT(MONTH FROM report.prgrs_final_check_1st_comp_date) = '" . $month . "' " : "";
            $NotNullConditionPsozSelect4 = ($getDataBy == 'all_years') ? "AND report.prgrs_final_check_1st_comp_date IS NOT NULL " : "";

            $bindings = [];
            $sql = "
                SELECT
                    distinct 
                    u.id,
                    u.status,
                    user_offices.deleted_at as office_deleted,
                    CONCAT(u.first_name,' ',u.last_name) as mc_name,
                    offices.id as office_id,
                    offices.name as office_name,
                    cities.id as city_id,
                    cities.name as city_name,
                    COALESCE(SUM(case when report.final1_id = u.id ".$yearConditionPsozSelect4."  ".$monthConditionPsozSelect4." ".$NotNullConditionPsozSelect4." then 1 else 0 end), 0) as final_1
                FROM
                    users as u
                INNER JOIN user_offices on
                    u.id = user_offices.user_id 
                INNER JOIN offices on
                    user_offices.office_id = offices.id
                INNER JOIN cities on
                    offices.city_id = cities.id
                INNER JOIN office_departments as od on
                    offices.id = od.office_id
                LEFT JOIN LATERAL (
                    select
                        psoz.final1_id,
                        psoz.prgrs_final_check_1st_comp_date
                    from
                        project_sozoku as psoz
                    inner join 
                        office_departments od1 on psoz.office_departments_id = od1.id
                    where
                        psoz.deleted_at is null
                        and psoz.final1_id = u.id
                        and psoz.final1_office_id = offices.id
                ) as report on true
                WHERE
                    u.department_id = ".$this->department_id." 
                    and u.id NOT IN " . $this->js_admin_ids_raw . " 
                    and u.deleted_at is null 
                    and offices.status = 1  
                    and od.status = 1 
                    and od.department_id = ".$this->department_id." ";  
            
            if(!empty($city_id)) {
                $sql .= "AND offices.city_id = ? ";
                $bindings[] = $city_id;
            }
            if(!empty($office_id)) {
                $sql .= "AND offices.id = ? ";
                $bindings[] = $office_id;
            }
            if(!empty($user_ids) && count($user_ids)) {
                $placeholders = implode(', ', array_fill(0, count($user_ids), '?'));
                $sql .= "AND u.id IN ($placeholders) ";
                $bindings = array_merge($bindings, $user_ids);
            }
                    
            $sql .= "GROUP BY
                    u.id,
                    offices.id,
                    cities.id,
                    user_offices.deleted_at
                ORDER BY
                    offices.id asc,
                    u.id asc";
            
            // echo $sql;exit;
            $final1_data = DB::select($sql, $bindings);
            /* ************************************ FINAL 1 CODE - END ************************************* */
            
            /* ************************************ FINAL 2 CODE ************************************* */
            $yearConditionPsozSelect5 = !empty($year) ? "AND EXTRACT(YEAR FROM report.prgrs_final_check_2nd_comp_date) = '" . $year . "'" : "";
            $monthConditionPsozSelect5 = !empty($month) ? "AND EXTRACT(MONTH FROM report.prgrs_final_check_2nd_comp_date) = '" . $month . "' " : "";
            $NotNullConditionPsozSelect5 = ($getDataBy == 'all_years') ? "AND report.prgrs_final_check_2nd_comp_date IS NOT NULL " : "";

            $bindings = [];
            $sql = "
                SELECT
                    distinct 
                    u.id,
                    u.status,
                    user_offices.deleted_at as office_deleted,
                    CONCAT(u.first_name,' ',u.last_name) as mc_name,
                    offices.id as office_id,
                    offices.name as office_name,
                    cities.id as city_id,
                    cities.name as city_name,
                    COALESCE(SUM(case when report.final2_id = u.id ".$yearConditionPsozSelect5."  ".$monthConditionPsozSelect5." ".$NotNullConditionPsozSelect5." then 1 else 0 end), 0) as final_2
                FROM
                    users as u
                INNER JOIN user_offices on
                    u.id = user_offices.user_id 
                INNER JOIN offices on
                    user_offices.office_id = offices.id
                INNER JOIN cities on
                    offices.city_id = cities.id
                INNER JOIN office_departments as od on
                    offices.id = od.office_id
                LEFT JOIN LATERAL (
                    select
                        psoz.final2_id,
                        psoz.prgrs_final_check_2nd_comp_date
                    from
                        project_sozoku as psoz
                    inner join 
                        office_departments od1 on psoz.office_departments_id = od1.id
                    where
                        psoz.deleted_at is null
                        and psoz.final2_id = u.id
                        and psoz.final2_office_id = offices.id
                ) as report on true
                WHERE
                    u.department_id = ".$this->department_id." 
                    and u.id NOT IN " . $this->js_admin_ids_raw . " 
                    and u.deleted_at is null 
                    and offices.status = 1  
                    and od.status = 1 
                    and od.department_id = ".$this->department_id." ";  
            
            if(!empty($city_id)) {
                $sql .= "AND offices.city_id = ? ";
                $bindings[] = $city_id;
            }
            if(!empty($office_id)) {
                $sql .= "AND offices.id = ? ";
                $bindings[] = $office_id;
            }
            if(!empty($user_ids) && count($user_ids)) {
                $placeholders = implode(', ', array_fill(0, count($user_ids), '?'));
                $sql .= "AND u.id IN ($placeholders) ";
                $bindings = array_merge($bindings, $user_ids);
            }
                    
            $sql .= "GROUP BY
                    u.id,
                    offices.id,
                    cities.id,
                    user_offices.deleted_at
                ORDER BY
                    offices.id asc,
                    u.id asc";
            
            // echo $sql;exit;
            $final2_data = DB::select($sql, $bindings);
            /* ************************************ FINAL 2 CODE - END ************************************* */
            
            /* ************************************ TOTAL AMOUNT ************************************* */

            $yearConditionPsozWhere1 = !empty($year) ? "AND EXTRACT(YEAR FROM psoz.prgrs_verify_1st_comp_date) = '" . $year . "'" : "";
            $monthConditionPsozWhere1 = !empty($month) ? "AND EXTRACT(MONTH FROM psoz.prgrs_verify_1st_comp_date) = '" . $month . "' " : "";
            $notNullConditionPsozWhere1 = ($getDataBy == 'all_years') ? "AND psoz.prgrs_verify_1st_comp_date IS NOT NULL " : "";
            
            $yearConditionPsozWhere2 = !empty($year) ? "AND EXTRACT(YEAR FROM psoz.prgrs_verify_2nd_comp_date) = '" . $year . "'" : "";
            $monthConditionPsozWhere2 = !empty($month) ? "AND EXTRACT(MONTH FROM psoz.prgrs_verify_2nd_comp_date) = '" . $month . "' " : "";
            $notNullConditionPsozWhere2 = ($getDataBy == 'all_years') ? "AND psoz.prgrs_verify_2nd_comp_date IS NOT NULL " : "";
            
            $yearConditionPsozWhere3 = !empty($year) ? "AND EXTRACT(YEAR FROM psoz.prgrs_verify_3rd_comp_date) = '" . $year . "'" : "";
            $monthConditionPsozWhere3 = !empty($month) ? "AND EXTRACT(MONTH FROM psoz.prgrs_verify_3rd_comp_date) = '" . $month . "' " : "";
            $notNullConditionPsozWhere3 = ($getDataBy == 'all_years') ? "AND psoz.prgrs_verify_3rd_comp_date IS NOT NULL " : "";

            $bindings = [];
            $sql = "
                SELECT
                    distinct 
                    u.id,
                    u.status,
                    user_offices.deleted_at as office_deleted,
                    CONCAT(u.first_name,' ',u.last_name) as mc_name,
                    offices.id as office_id,
                    offices.name as office_name,
                    cities.id as city_id,
                    cities.name as city_name,
                    coalesce(SUM(report.total_amount), 0) as total_amount
                FROM
                    users as u
                INNER JOIN user_offices on
                    u.id = user_offices.user_id 
                    -- and user_offices.deleted_at is null
                INNER JOIN offices on
                    user_offices.office_id = offices.id
                INNER JOIN cities on
                    offices.city_id = cities.id
                INNER JOIN office_departments as od on
                    offices.id = od.office_id
                LEFT JOIN LATERAL (
                    select
                        psoz.checker1_id,
                        psoz.checker2_id,
                        psoz.checker3_id,
                        psoz.prgrs_verify_1st_comp_date,
                        psoz.prgrs_verify_2nd_comp_date,
                        psoz.prgrs_verify_3rd_comp_date,
                        COALESCE(psoz.deposit_amount, 0) + COALESCE(psoz.balance_amount, 0) AS total_amount
                    from
                        project_sozoku as psoz
                    inner join 
                        office_departments od1 on psoz.office_departments_id = od1.id 
                    where
                        psoz.deleted_at is null
                        and (
                            (psoz.checker1_id = u.id ".$yearConditionPsozWhere1."  ".$monthConditionPsozWhere1." ".$notNullConditionPsozWhere1." ) OR 
                            (psoz.checker2_id = u.id ".$yearConditionPsozWhere2."  ".$monthConditionPsozWhere2." ".$notNullConditionPsozWhere2." ) OR 
                            (psoz.checker3_id = u.id ".$yearConditionPsozWhere3."  ".$monthConditionPsozWhere3." ".$notNullConditionPsozWhere3." )
                        )
                        and (
                            (psoz.checker1_id = u.id AND psoz.checker1_office_id = offices.id) OR 
                            (psoz.checker2_id = u.id AND psoz.checker2_office_id = offices.id) OR 
                            (psoz.checker3_id = u.id AND psoz.checker3_office_id = offices.id)
                        )
                ) as report on true
                WHERE
                    u.department_id = ".$this->department_id." 
                    and u.id NOT IN " . $this->js_admin_ids_raw . " 
                    and u.deleted_at is null 
                    and offices.status = 1  
                    and od.status = 1 
                    and od.department_id = ".$this->department_id." ";  
            
            if(!empty($city_id)) {
                $sql .= "AND offices.city_id = ? ";
                $bindings[] = $city_id;
            }
            if(!empty($office_id)) {
                $sql .= "AND offices.id = ? ";
                $bindings[] = $office_id;
            }
            if(!empty($user_ids) && count($user_ids)) {
                $placeholders = implode(', ', array_fill(0, count($user_ids), '?'));
                $sql .= "AND u.id IN ($placeholders) ";
                $bindings = array_merge($bindings, $user_ids);
            }
                    
            $sql .= "GROUP BY
                    u.id,
                    offices.id,
                    cities.id,
                    user_offices.deleted_at
                ORDER BY
                    offices.id asc,
                    u.id asc";
            
            // echo $sql;exit;
            $total_amount_data = DB::select($sql, $bindings);
            /* ************************************ TOTAL AMOUNT - END ************************************* */

            // if($getDataBy == 'all_years') {
                // dd($checker1_data, $checker2_data, $checker3_data, $final1_data, $final2_data, $total_amount_data);
            // }

            $final_data = [];
            $i = 0;
            foreach($checker1_data as $k => $d1) {
                $final_data[$i] = $d1;
                if(isset($checker2_data[$k]) && $checker2_data[$k]->id == $d1->id && $checker2_data[$k]->office_id == $d1->office_id ) {
                    $final_data[$i]->accounts_2 = $checker2_data[$k]->accounts_2;
                }
                else {
                    $final_data[$i]->accounts_2 = 0;
                }
                
                if(isset($checker3_data[$k]) && $checker3_data[$k]->id == $d1->id && $checker3_data[$k]->office_id == $d1->office_id ) {
                    $final_data[$i]->accounts_3 = $checker3_data[$k]->accounts_3;
                }
                else {
                    $final_data[$i]->accounts_3 = 0;
                }
                
                if(isset($final1_data[$k]) && $final1_data[$k]->id == $d1->id && $final1_data[$k]->office_id == $d1->office_id ) {
                    $final_data[$i]->final_1 = $final1_data[$k]->final_1;
                }
                else {
                    $final_data[$i]->final_1 = 0;
                }
                
                if(isset($final2_data[$k]) && $final2_data[$k]->id == $d1->id && $final2_data[$k]->office_id == $d1->office_id ) {
                    $final_data[$i]->final_2 = $final2_data[$k]->final_2;
                }
                else {
                    $final_data[$i]->final_2 = 0;
                }
                
                if(isset($total_amount_data[$k]) && $total_amount_data[$k]->id == $d1->id && $total_amount_data[$k]->office_id == $d1->office_id ) {
                    $final_data[$i]->total_amount = $total_amount_data[$k]->total_amount;
                }
                else {
                    $final_data[$i]->total_amount = 0;
                }
                
                $i++;
            }

            // dd($data1, $data2, $final_data);
            return $final_data;
        } 
        catch (Exception $e) {
            $errorMessage = $e->getMessage();
            $errorFile = $e->getFile();
            $errorLine = $e->getLine();

            // Combine the error message with its location
            $errorDetails = [
                'message' => $errorMessage,
                'file' => $errorFile,
                'line' => $errorLine,
            ];

            // Assuming sendError is a method that can accept an array of error details
            return $this->sendError(__('something_went_wrong'), $errorDetails, 400);
        }
    }

    private function tab4Array($cur_rec, $ex_rec = [])
    {
        if(count($ex_rec)) {
            $_amount = $cur_rec->total_amount + str_replace(",", "", $ex_rec['amount']);
            $_amount = number_format($_amount, 0, '.', ',');

            $data = [
                'verification_of_accounts'  => $cur_rec->accounts_1 + $cur_rec->accounts_2 + $cur_rec->accounts_3 + $ex_rec['verification_of_accounts'],
                'amount'                    => $_amount,
                'final'                     => $cur_rec->final_1 + $cur_rec->final_2 + $ex_rec['final'],
            ];
        }
        else {
            $_amount = $cur_rec->total_amount;
            $_amount = number_format($_amount, 0, '.', ',');

            $data = [
                'verification_of_accounts'  => $cur_rec->accounts_1 + $cur_rec->accounts_2 + $cur_rec->accounts_3,
                'amount'                    => $_amount,
                'final'                     => $cur_rec->final_1 + $cur_rec->final_2,
            ];
        }

        return $data;
    }
    
    private function tab4DefaultArray()
    {
        $data = [
            'verification_of_accounts'  => 0,
            'amount'                    => 0,
            'final'                     => 0,
        ];
        
        return $data;
    }

    // TAB 5
    public function tab5(Request $request)
    {
        try {
            // RESPONSE DATA CODE
            $response_data = [];            
            
            $response_data = $this->tab5Data($request);
            // dd($response_data);

            // ALL DATA
            $worker_all_data = $teams_all_data = $inactive_workers_data = $others_all_data = $offices_all_data = $total_column_data = [];
            foreach($response_data as $key => $w_rec) {
                // ALL WORKERs DATA
                // if($w_rec->status == 1 && empty($w_rec->office_deleted)) {
                if($w_rec->status == 1) {
                    if(!isset($worker_all_data[$w_rec->id.'-'.$w_rec->office_id])) {
                        $array1 = [
                            'Base' => $w_rec->office_name,
                            'Team' => !empty($w_rec->team_name) ? $w_rec->team_name : '',
                            'Worker' => $w_rec->mc_name
                        ];
                        $array2 = $this->tab5Array($w_rec);

                        $worker_all_data[$w_rec->id.'-'.$w_rec->office_id] = array_merge($array1, $array2);
                    }
                }

                // ALL TEAMS DATA
                /* if($w_rec->status == 1 && !empty($w_rec->team_id) && $w_rec->office_deleted == null) {
                    if(!isset($teams_all_data['team-' . $w_rec->team_id])) {
                        $array1 = [
                            'Base' => $w_rec->office_name,
                            'Team' => $w_rec->team_name,
                            'Worker' => __('totalling')
                        ];
                        $array2 = $this->tab5Array($w_rec);

                        $teams_all_data['team-' . $w_rec->team_id] = array_merge($array1, $array2);
                    }                    
                    elseif(isset($teams_all_data['team-' . $w_rec->team_id])) {
                        $existing_array = $teams_all_data['team-' . $w_rec->team_id];

                        $teams_all_data['team-' . $w_rec->team_id] = $this->tab5Array($w_rec, $existing_array);
                    }
                } */

                /* // ALL INACTIVE WORKERs DATA
                if($w_rec->status == 0) {
                    if(!isset($inactive_workers_data['others'])) {
                        $array1 = [
                            'Base' => 'All base',
                            'Team' => __('others'),
                            'Worker' => __('totalling')
                        ];
                        $array2 = $this->tab5Array($w_rec);

                        $inactive_workers_data['others'] = array_merge($array1, $array2);
                    }
                    elseif(isset($inactive_workers_data['others'])) {
                        $existing_array = $inactive_workers_data['others'];

                        $inactive_workers_data['others'] = $this->tab5Array($w_rec, $existing_array);
                    }
                } */

                // ALL OTHERS DATA
                // if(($w_rec->status == 0 || !empty($w_rec->office_deleted))) {
                if(($w_rec->status == 0)) {
                    if(!isset($others_all_data['others']) ) {
                        $array1 = [
                            'Base' => '',
                            'Team' => __('others'),
                            'Worker' => __('others')
                        ];
                        $array2 = $this->tab5Array($w_rec);
    
                        $others_all_data['others'] = array_merge($array1, $array2);
                    }
                    elseif(isset($others_all_data['others'])) {
                        $existing_array = $others_all_data['others'];
    
                        $others_all_data['others'] = $this->tab5Array($w_rec, $existing_array);
                    }
                }

                // ALL OFFICES DATA
                if(!isset($offices_all_data['office-' . $w_rec->office_id]) && $w_rec->status == 1) {
                    $array1 = [
                        'Base' => $w_rec->office_name,
                        'Team' => 'All teams',
                        'Worker' => __('totalling')
                    ];
                    $array2 = $this->tab5Array($w_rec);

                    $offices_all_data['office-' . $w_rec->office_id] = array_merge($array1, $array2);
                }
                elseif(isset($offices_all_data['office-' . $w_rec->office_id])  && $w_rec->status == 1) {
                    $existing_array = $offices_all_data['office-' . $w_rec->office_id];

                    $offices_all_data['office-' . $w_rec->office_id] = $this->tab5Array($w_rec, $existing_array);
                }

                // ALL TOTAL DATA
                if(!isset($total_column_data['column_wise_total']) ) {
                    $array1 = [
                        'Base' => __('grand_total'),
                        'Team' => '',
                        'Worker' => ''
                    ];
                    $array2 = $this->tab5Array($w_rec);

                    $total_column_data['column_wise_total'] = array_merge($array1, $array2);
                }
                elseif(isset($total_column_data['column_wise_total'])) {
                    $existing_array = $total_column_data['column_wise_total'];

                    $total_column_data['column_wise_total'] = $this->tab5Array($w_rec, $existing_array);
                }
            }
            // dd($worker_all_data, $teams_all_data);

            $data = [
                'worker_data' => count($worker_all_data) ? $worker_all_data : (object)[],
                // 'teams_data' => count($teams_all_data) ? $teams_all_data : (object)[],
                // 'inactive_worker_data' => count($inactive_workers_data) ? $inactive_workers_data : (object)[],
                'others_data' => count($others_all_data) ? $others_all_data : (object)[],
                'office_data' => count($offices_all_data) ? $offices_all_data : (object)[],
                'grand_total' => count($total_column_data) ? $total_column_data : (object)[],
            ];
            
	        if (count($data)) {
	            return $this->sendResponse($data, __('record_found'));
	        }

	        return $this->sendResponse([], __('record_not_found'));
	    } 
	    catch (Exception $e) {
	        $errorMessage = $e->getMessage();
            $errorFile = $e->getFile();
            $errorLine = $e->getLine();

            // Combine the error message with its location
            $errorDetails = [
                'message' => $errorMessage,
                'file' => $errorFile,
                'line' => $errorLine,
            ];

            // Assuming sendError is a method that can accept an array of error details
            return $this->sendError(__('something_went_wrong'), $errorDetails, 400);
	    }
    }

    // TAB 5 - DATA
    private function tab5Data($request) 
    {
        try {
            $city_id = $request->input('city_id', '');
            $office_id = $request->input('office_id', '');
            $user_ids = $request->input('user_ids', '[]');
            $user_ids = is_array($user_ids) ? $user_ids : [];

            $bindings = [];
            $sql = "
                select
                    distinct 
                    u.id,
                    u.status,
                    user_offices.deleted_at as office_deleted,
                    CONCAT(u.first_name,' ',u.last_name) as mc_name,
                    offices.id as office_id,
                    offices.name as office_name,
                    teams.id as team_id,
                    teams.name as team_name,
                    COALESCE(SUM(COALESCE(project1.worker1_count, 0) + COALESCE(project3.worker3_count, 0)), 0) AS worker1_count,
                    COALESCE(SUM(COALESCE(project2.worker2_count, 0) + COALESCE(project3.worker3_count, 0)), 0) AS worker2_count,
                    COALESCE(SUM(COALESCE(project1.worker1_count, 0) + COALESCE(project2.worker2_count, 0) + COALESCE(project3.worker3_count, 0)), 0) AS work_involve_count,
                    COALESCE(SUM(project4.checker1_count), 0) AS checker1_count,
                    COALESCE(SUM(project5.checker2_count), 0) AS checker2_count,
                    COALESCE(SUM(project6.interviewer_count), 0) AS interviewer_count,
                    COALESCE(SUM(project7.worker1_active_count), 0) AS worker1_active_count
                from
                    users as u
                inner join user_offices on
                    u.id = user_offices.user_id 
                    -- and user_offices.deleted_at is null
                inner join offices on
                    user_offices.office_id = offices.id
                inner join office_departments as od on
                    offices.id = od.office_id
                left join user_teams on
                    u.id = user_teams.user_id and user_teams.deleted_at is null
                left join teams on
                    user_teams.team_id = teams.id
                left join lateral (
                    select
                        -- psoz.worker_id,
                        count(psoz.id) as worker1_count 
                    from
                        project_sozoku as psoz
                    inner join 
                        office_departments od on psoz.office_departments_id = od.id 
                        -- and od.office_id = offices.id
                    where
                        psoz.deleted_at is null and 
                        psoz.prgrs_taxoffice_shipping_date is null and
                        ((psoz.worker_id != psoz.manager_id) or (psoz.worker_id is not null and psoz.manager_id is null)) and
                        psoz.worker_id = u.id and
                        psoz.worker_office_id = offices.id
                    -- group by psoz.worker_id
                ) as project1 on true
                left join lateral (
                    select
                        -- psoz.manager_id,
                        count(psoz.id) as worker2_count 
                    from
                        project_sozoku as psoz
                    inner join 
                        office_departments od on psoz.office_departments_id = od.id 
                        -- and od.office_id = offices.id
                    where
                        psoz.deleted_at is null and 
                        psoz.prgrs_taxoffice_shipping_date is null and 
                        ((psoz.worker_id != psoz.manager_id) or (psoz.worker_id is null and psoz.manager_id is not null)) and
                        psoz.manager_id = u.id and
                        psoz.manager_office_id = offices.id
                    -- group by psoz.manager_id
                ) as project2 on true
                left join lateral (
                    select
                        -- psoz.worker_id,
                        count(psoz.id) as worker3_count 
                    from
                        project_sozoku as psoz
                    inner join 
                        office_departments od on psoz.office_departments_id = od.id 
                        -- and od.office_id = offices.id
                    where
                        psoz.deleted_at is null and 
                        psoz.prgrs_taxoffice_shipping_date is null and 
                        psoz.worker_id = psoz.manager_id and
                        psoz.worker_id = u.id and
                        psoz.worker_office_id = offices.id
                    -- group by psoz.worker_id
                ) as project3 on true
                left join lateral (
                    select
                        -- psoz.checker1_id,
                        count(psoz.id) as checker1_count 
                    from
                        project_sozoku as psoz
                    inner join 
                        office_departments od on psoz.office_departments_id = od.id 
                        -- and od.office_id = offices.id
                    where
                        psoz.deleted_at is null and 
                        psoz.prgrs_verify_1st_comp_date is null and 
                        psoz.checker1_id = u.id and
                        psoz.checker1_office_id = offices.id
                    -- group by psoz.checker1_id
                ) as project4 on true
                left join lateral (
                    select
                        -- psoz.checker2_id,
                        count(psoz.id) as checker2_count 
                    from
                        project_sozoku as psoz
                    inner join 
                        office_departments od on psoz.office_departments_id = od.id 
                        -- and od.office_id = offices.id
                    where
                        psoz.deleted_at is null and 
                        psoz.prgrs_verify_2nd_comp_date is null and 
                        psoz.checker2_id = u.id and
                        psoz.checker2_office_id = offices.id
                    -- group by psoz.checker2_id
                ) as project5 on true
                left join lateral (
                    select
                        -- psoz.interviewer_id,
                        count(psoz.id) as interviewer_count 
                    from
                        project_sozoku as psoz
                    inner join 
                        office_departments od on psoz.office_departments_id = od.id 
                        -- and od.office_id = offices.id
                    where
                        psoz.deleted_at is null and 
                        psoz.prgrs_return_date is null and 
                        psoz.interviewer_id = u.id and
                        psoz.interviewer_office_id = offices.id
                    -- group by psoz.interviewer_id
                ) as project6 on true
                left join lateral (
                    select
                        -- psoz.worker_id,
                        count(psoz.id) as worker1_active_count
                    from
                        project_sozoku as psoz
                    inner join 
                        office_departments od on psoz.office_departments_id = od.id 
                        -- and od.office_id = offices.id
                    where
                        psoz.deleted_at is null and 
                        psoz.prgrs_taxoffice_shipping_date is null and 
                        psoz.prgrs_doc_recv_date is not null and
                        psoz.prgrs_verify_1st_submit_date is null and
                        psoz.worker_id = u.id and
                        psoz.worker_office_id = offices.id
                    -- group by psoz.worker_id
                ) as project7 on true
                where
                    u.department_id = ".$this->department_id." and
                    u.id NOT IN " . $this->js_admin_ids_raw . " and 
                    u.deleted_at is null and 
                    offices.status = 1 and
                    od.status = 1 and
                    od.department_id = ".$this->department_id." 
                group by
                    u.id,
                    offices.id,
                    teams.id,
                    user_offices.deleted_at
                order by
                    offices.id asc,
                    u.id asc
            ";

            // echo $sql;exit;
            $data = DB::select($sql, $bindings);
            
            // dd($data);
            return $data;
        } 
        catch (Exception $e) {
            $errorMessage = $e->getMessage();
            $errorFile = $e->getFile();
            $errorLine = $e->getLine();

            // Combine the error message with its location
            $errorDetails = [
                'message' => $errorMessage,
                'file' => $errorFile,
                'line' => $errorLine,
            ];

            // Assuming sendError is a method that can accept an array of error details
            return $this->sendError(__('something_went_wrong'), $errorDetails, 400);
        }
    }

    /**
     * Current loop record - $cur_rec
     * Existing record - $ex_rec
     */
    private function tab5Array($cur_rec, $ex_rec = [])
    {
        if(count($ex_rec)) {
            $data = $ex_rec;
            $data['worker_1'] = $cur_rec->worker1_count + $ex_rec['worker_1'];
            $data['worker_2'] = $cur_rec->worker2_count + $ex_rec['worker_2'];
            $data['work_involve_count'] = $cur_rec->work_involve_count + $ex_rec['work_involve_count'];
            $data['checker1_count'] = $cur_rec->checker1_count + $ex_rec['checker1_count'];
            $data['checker2_count'] = $cur_rec->checker2_count + $ex_rec['checker2_count'];
            $data['interviewer_count'] = $cur_rec->interviewer_count + $ex_rec['interviewer_count'];
            $data['worker1_active_count'] = $cur_rec->worker1_active_count + $ex_rec['worker1_active_count'];
        }
        else {
            $data = [
                'worker_1' => $cur_rec->worker1_count,
                'worker_2' => $cur_rec->worker2_count,
                'work_involve_count' => $cur_rec->work_involve_count,
                'checker1_count' => $cur_rec->checker1_count,
                'checker2_count' => $cur_rec->checker2_count,
                'interviewer_count' => $cur_rec->interviewer_count,
                'worker1_active_count' => $cur_rec->worker1_active_count,
            ];
        }

        return $data;
    }

    // TAB 6
    public function tab6(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'start_date' => 'required|date_format:Y-m',
                'end_date' => 'required|date_format:Y-m',
                'is_yearly_total' => 'in:1,0',
                'is_grand_total' => 'in:1,0',
                'search_type' => 'required|in:area,office,employee',
            ]);
        
            if ($validator->fails()) {
                $errors = $validator->errors();
                $error_array = [];
                if($errors->first('start_date')) {
                    $error_array['start_date'] = [$errors->first('start_date')];
                }
                if($errors->first('end_date')) {
                    $error_array['end_date'] = [$errors->first('end_date')];
                }
                if($errors->first('is_yearly_total')) {
                    $error_array['is_yearly_total'] = [$errors->first('is_yearly_total')];
                }
                if($errors->first('is_grand_total')) {
                    $error_array['is_grand_total'] = [$errors->first('is_grand_total')];
                }
                if($errors->first('search_type')) {
                    $error_array['search_type'] = [$errors->first('search_type')];
                }
                return $this->sendError(__('something_went_wrong'), $error_array, 422);
            }

            $search_type = $request->input('search_type', '');      // Possible values area, office, employee
            $is_yearly_total = $request->input('is_yearly_total', 0);
            $is_grand_total = $request->input('is_grand_total', 0);

            // Assuming $start_date and $end_date are in 'Y-m' format, e.g., '2023-12'
            $start_date = Carbon::createFromFormat('Y-m-d', $request->start_date.'-01');
            $end_date = Carbon::createFromFormat('Y-m-d', $request->end_date.'-01')->endOfMonth(); // Ensure the end date covers the end of the month

            $currentDate = $start_date->copy();
            $previousYear = $start_date->year;

            // RESPONSE DATA CODE
            $response_data = [];            
            // Initial run for Function
            $response_data[$currentDate->year][$currentDate->format('m')] = $this->tab6Data($request, $currentDate, 'month');
            // Move to the next month for the loop start
            $currentDate->addMonth();
            
            while ($currentDate->lessThanOrEqualTo($end_date)) {
                // Check if the year has changed, indicating the start of a new year
                if ($currentDate->year != $previousYear && $is_yearly_total == 1) {
                    // Run Function for total of year calculation before processing the new year
                    $response_data[$previousYear]['year_total'] = $this->tab6Data($request, $previousYear, 'year');
                    $previousYear = $currentDate->year;
                }

                // Run Function for the current month
                $response_data[$currentDate->year][$currentDate->format('m')] = $this->tab6Data($request, $currentDate, 'month');

                // Move to the next month
                $currentDate->addMonth();
            }

            // After completing the loop, check if Function needs to be called for the end date's year
            if ($end_date->year == $previousYear && $is_yearly_total == 1) {
                $response_data[$end_date->year]['year_total'] = $this->tab6Data($request, $end_date->year, 'year');
            }
            
            // For grand total row wise
            if($is_grand_total == 1) {
                $response_data['all_years']['grand_total'] = $this->tab6Data($request, $end_date->year, 'all_years');
            }
            // dd($response_data);

            // ALL DATA
            $mc_all_data = $others_all_data = $cities_all_data = $offices_all_data = $total_column_data = [];
            foreach($response_data as $year => $record) {
                
                if(is_array($record)) {
                    foreach($record as $month => $mc_records) {

                        if(is_array($mc_records)) {
                            foreach($mc_records as $w_rec) {
                                $worker_check = $others_check = 0;
                                // ALL MCs DATA
                                // if($w_rec->status == 1 && $w_rec->office_deleted == null && $search_type == 'employee') {
                                if($w_rec->status == 1 && $search_type == 'employee') {
                                    $worker_check = 1;
                                    if(!isset($mc_all_data[$w_rec->id.'-'.$w_rec->office_id]) && $month != 'year_total' && $month != 'grand_total') {
                                        $mc_all_data[$w_rec->id.'-'.$w_rec->office_id] = [
                                            'Base' => $w_rec->office_name,
                                            'MC' => $w_rec->mc_name,
                                            $this->months[$month] . ' ' . $year => $this->tab6Array($w_rec)
                                        ];
                                    }
                                    elseif(isset($mc_all_data[$w_rec->id.'-'.$w_rec->office_id]) && $month != 'year_total' && $month != 'grand_total') {
                                        $mc_all_data[$w_rec->id.'-'.$w_rec->office_id][$this->months[$month] . ' ' . $year] = $this->tab6Array($w_rec);
                                    }
                                    elseif(isset($mc_all_data[$w_rec->id.'-'.$w_rec->office_id]) && $month == 'year_total') {
                                        $mc_all_data[$w_rec->id.'-'.$w_rec->office_id][$year . ' Total'] = $this->tab6Array($w_rec);
                                    }
                                    elseif(isset($mc_all_data[$w_rec->id.'-'.$w_rec->office_id]) && $month == 'grand_total') {
                                        $mc_all_data[$w_rec->id.'-'.$w_rec->office_id]['Grand Total'] = $this->tab6Array($w_rec);
                                    }
                                }

                                // ALL OTHERS DATA
                                // if(($w_rec->status == 0 || !empty($w_rec->office_deleted)) && $search_type == 'employee') {
                                if(($w_rec->status == 0) && $search_type == 'employee') {
                                    $others_check = 1;
                                    if(!isset($others_all_data['others']) && $month != 'year_total' && $month != 'grand_total') {
                                        $others_all_data['others'] = [
                                            'Base' => '',
                                            'MC' => __('others'),
                                            $this->months[$month] . ' ' . $year => $this->tab6Array($w_rec)
                                        ];
                                    }
                                    elseif(isset($others_all_data['others']) && isset($this->months[$month]) && !isset($others_all_data['others'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $others_all_data['others'][$this->months[$month] . ' ' . $year] = $this->tab6Array($w_rec);
                                    }
                                    elseif(isset($others_all_data['others']) && !isset($others_all_data['others'][$year . ' Total']) && $month == 'year_total') {
                                        $others_all_data['others'][$year . ' Total'] = $this->tab6Array($w_rec);
                                    }
                                    elseif(isset($others_all_data['others']) && !isset($others_all_data['others']['Grand Total']) && $month == 'grand_total') {
                                        $others_all_data['others']['Grand Total'] = $this->tab6Array($w_rec);
                                    }
                                    elseif(isset($others_all_data['others']) && isset($this->months[$month]) && isset($others_all_data['others'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $others_all_data['others'][$this->months[$month] . ' ' . $year];
                                        $others_all_data['others'][$this->months[$month] . ' ' . $year] = $this->tab6Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($others_all_data['others']) && isset($others_all_data['others'][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $others_all_data['others'][$year . ' Total'];
                                        $others_all_data['others'][$year . ' Total'] = $this->tab6Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($others_all_data['others']) && isset($others_all_data['others']['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $others_all_data['others']['Grand Total'];
                                        $others_all_data['others']['Grand Total'] = $this->tab6Array($w_rec, $existing_array);
                                    }
                                }

                                // IF THERE IS NO OTHERS DATA IN CURRENT ITERATION THEN ADD A DEFAULT DATA
                                if($worker_check == 1 && $others_check == 0) {
                                    if(!isset($others_all_data['others']) && $month != 'year_total' && $month != 'grand_total') {
                                        $others_all_data['others'] = [
                                            'Base' => '',
                                            'MC' => __('others'),
                                            $this->months[$month] . ' ' . $year => $this->tab6DefaultArray()
                                        ];
                                    }
                                    elseif(isset($others_all_data['others']) && isset($this->months[$month]) && !isset($others_all_data['others'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $others_all_data['others'][$this->months[$month] . ' ' . $year] = $this->tab6DefaultArray();
                                    }
                                    elseif(isset($others_all_data['others']) && !isset($others_all_data['others'][$year . ' Total']) && $month == 'year_total') {
                                        $others_all_data['others'][$year . ' Total'] = $this->tab6DefaultArray();
                                    }
                                    elseif(isset($others_all_data['others']) && !isset($others_all_data['others']['Grand Total']) && $month == 'grand_total') {
                                        $others_all_data['others']['Grand Total'] = $this->tab6DefaultArray();
                                    }
                                }

                                // ALL AREAS DATA
                                if($search_type == 'area') {
                                    if(!isset($cities_all_data['city-'.$w_rec->city_id]) && $month != 'year_total' && $month != 'grand_total') {
                                        $cities_all_data['city-'.$w_rec->city_id] = [
                                            'Base' => $w_rec->city_name,
                                            'MC' => __('total'),
                                            $this->months[$month] . ' ' . $year => $this->tab6Array($w_rec)
                                        ];
                                    }
                                    elseif(isset($cities_all_data['city-'.$w_rec->city_id]) && isset($this->months[$month]) && !isset($cities_all_data['city-'.$w_rec->city_id][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $cities_all_data['city-'.$w_rec->city_id][$this->months[$month] . ' ' . $year] = $this->tab6Array($w_rec);
                                    }
                                    elseif(isset($cities_all_data['city-'.$w_rec->city_id]) && !isset($cities_all_data['city-'.$w_rec->city_id][$year . ' Total']) && $month == 'year_total') {
                                        $cities_all_data['city-'.$w_rec->city_id][$year . ' Total'] = $this->tab6Array($w_rec);
                                    }
                                    elseif(isset($cities_all_data['city-'.$w_rec->city_id]) && !isset($cities_all_data['city-'.$w_rec->city_id]['Grand Total']) && $month == 'grand_total') {
                                        $cities_all_data['city-'.$w_rec->city_id]['Grand Total'] = $this->tab6Array($w_rec);
                                    }
                                    elseif(isset($cities_all_data['city-'.$w_rec->city_id]) && isset($this->months[$month]) && isset($cities_all_data['city-'.$w_rec->city_id][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $cities_all_data['city-'.$w_rec->city_id][$this->months[$month] . ' ' . $year];
                                        $cities_all_data['city-'.$w_rec->city_id][$this->months[$month] . ' ' . $year] = $this->tab6Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($cities_all_data['city-'.$w_rec->city_id]) && isset($cities_all_data['city-'.$w_rec->city_id][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $cities_all_data['city-'.$w_rec->city_id][$year . ' Total'];
                                        $cities_all_data['city-'.$w_rec->city_id][$year . ' Total'] = $this->tab6Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($cities_all_data['city-'.$w_rec->city_id]) && isset($cities_all_data['city-'.$w_rec->city_id]['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $cities_all_data['city-'.$w_rec->city_id]['Grand Total'];
                                        $cities_all_data['city-'.$w_rec->city_id]['Grand Total'] = $this->tab6Array($w_rec, $existing_array);
                                    }
                                }
                                
                                // ALL OFFICES DATA
                                if($search_type == 'office') {
                                    if(!isset($offices_all_data['office-'.$w_rec->city_id]) && $month != 'year_total' && $month != 'grand_total') {
                                        $offices_all_data['office-'.$w_rec->city_id] = [
                                            'Base' => $w_rec->office_name,
                                            'MC' => __('total'),
                                            $this->months[$month] . ' ' . $year => $this->tab6Array($w_rec)
                                        ];
                                    }
                                    elseif(isset($offices_all_data['office-'.$w_rec->city_id]) && isset($this->months[$month]) && !isset($offices_all_data['office-'.$w_rec->city_id][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $offices_all_data['office-'.$w_rec->city_id][$this->months[$month] . ' ' . $year] = $this->tab6Array($w_rec);
                                    }
                                    elseif(isset($offices_all_data['office-'.$w_rec->city_id]) && !isset($offices_all_data['office-'.$w_rec->city_id][$year . ' Total']) && $month == 'year_total') {
                                        $offices_all_data['office-'.$w_rec->city_id][$year . ' Total'] = $this->tab6Array($w_rec);
                                    }
                                    elseif(isset($offices_all_data['office-'.$w_rec->city_id]) && !isset($offices_all_data['office-'.$w_rec->city_id]['Grand Total']) && $month == 'grand_total') {
                                        $offices_all_data['office-'.$w_rec->city_id]['Grand Total'] = $this->tab6Array($w_rec);
                                    }
                                    elseif(isset($offices_all_data['office-'.$w_rec->city_id]) && isset($this->months[$month]) && isset($offices_all_data['office-'.$w_rec->city_id][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $offices_all_data['office-'.$w_rec->city_id][$this->months[$month] . ' ' . $year];
                                        $offices_all_data['office-'.$w_rec->city_id][$this->months[$month] . ' ' . $year] = $this->tab6Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($offices_all_data['office-'.$w_rec->city_id]) && isset($offices_all_data['office-'.$w_rec->city_id][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $offices_all_data['office-'.$w_rec->city_id][$year . ' Total'];
                                        $offices_all_data['office-'.$w_rec->city_id][$year . ' Total'] = $this->tab6Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($offices_all_data['office-'.$w_rec->city_id]) && isset($offices_all_data['office-'.$w_rec->city_id]['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $offices_all_data['office-'.$w_rec->city_id]['Grand Total'];
                                        $offices_all_data['office-'.$w_rec->city_id]['Grand Total'] = $this->tab6Array($w_rec, $existing_array);
                                    }
                                }

                                // ALL TOTAL DATA
                                if(!isset($total_column_data['column_wise_total']) && $month != 'year_total' && $month != 'grand_total') {
                                    $total_column_data['column_wise_total'] = [
                                        'Base' => __('grand_total'),
                                        'MC' => '',
                                        $this->months[$month] . ' ' . $year => $this->tab6Array($w_rec)
                                    ];
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($this->months[$month]) && !isset($total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                    $total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year] = $this->tab6Array($w_rec);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && !isset($total_column_data['column_wise_total'][$year . ' Total']) && $month == 'year_total') {
                                    $total_column_data['column_wise_total'][$year . ' Total'] = $this->tab6Array($w_rec);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && !isset($total_column_data['column_wise_total']['Grand Total']) && $month == 'grand_total') {
                                    $total_column_data['column_wise_total']['Grand Total'] = $this->tab6Array($w_rec);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($this->months[$month]) && isset($total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                    $existing_array = $total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year];
                                    $total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year] = $this->tab6Array($w_rec, $existing_array);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($total_column_data['column_wise_total'][$year . ' Total']) && $month == 'year_total') {
                                    $existing_array = $total_column_data['column_wise_total'][$year . ' Total'];
                                    $total_column_data['column_wise_total'][$year . ' Total'] = $this->tab6Array($w_rec, $existing_array);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($total_column_data['column_wise_total']['Grand Total']) && $month == 'grand_total') {
                                    $existing_array = $total_column_data['column_wise_total']['Grand Total'];
                                    $total_column_data['column_wise_total']['Grand Total'] = $this->tab6Array($w_rec, $existing_array);
                                }
                            }
                        }
                    }
                }
            }
            // dd($worker_all_data, $teams_all_data);

            $data = [
                'search_type' => $search_type,
                'mc_data' => count($mc_all_data) ? $mc_all_data : (object)[],
                'others_data' => count($others_all_data) ? $others_all_data : (object)[],
                'area_data'   => count($cities_all_data) ? $cities_all_data : (object)[],
                'office_data' => count($offices_all_data) ? $offices_all_data : (object)[],
                'grand_total' => count($total_column_data) ? $total_column_data : (object)[],
            ];
            
	        if (count($data)) {
	            return $this->sendResponse($data, __('record_found'));
	        }

	        return $this->sendResponse([], __('record_not_found'));
	    } 
	    catch (Exception $e) {
	        $errorMessage = $e->getMessage();
            $errorFile = $e->getFile();
            $errorLine = $e->getLine();

            // Combine the error message with its location
            $errorDetails = [
                'message' => $errorMessage,
                'file' => $errorFile,
                'line' => $errorLine,
            ];

            // Assuming sendError is a method that can accept an array of error details
            return $this->sendError(__('something_went_wrong'), $errorDetails, 400);
	    }
    }

    // TAB 6 - DATA
    private function tab6Data($request, $dateOrYear, $getDataBy = 'month') 
    {
        try {    
            $month = $year = '';
            if($getDataBy == 'month') {
                $month = $dateOrYear->format('m');
                $year = $dateOrYear->format('Y');
            }
            elseif($getDataBy == 'year') {
                $year = $dateOrYear;
            }

            $city_id = $request->input('city_id', '');
            $office_id = $request->input('office_id', '');
            $user_ids = $request->input('user_ids', '[]');
            $user_ids = is_array($user_ids) ? $user_ids : [];

            $sql = "SELECT DISTINCT 
                    u.id,
                    u.status,
                    user_offices.deleted_at AS office_deleted,
                    CONCAT(u.first_name, ' ', u.last_name) AS mc_name,
                    offices.id AS office_id,
                    offices.name AS office_name,
                    cities.id AS city_id,
                    cities.name AS city_name,
                    COALESCE(tax_data.tax_count, 0) AS tax_count,
                    COALESCE(NULLIF(tax_data.interview_order_amount, 0), 0) AS tax_amount,
                    COALESCE(legal_data.legal_count, 0) AS legal_count,
                    COALESCE(NULLIF(legal_data.interview_order_amount, 0), 0) AS legal_amount,
                    COALESCE(insurance_data.insurance_count, 0) AS insurance_count,
                    COALESCE(NULLIF(insurance_data.commission, 0), 0) AS insurance_amount,
                    COALESCE(realestate_data.realestate_count, 0) AS realestate_count,
                    COALESCE(NULLIF(realestate_data.order_amount, 0), 0) AS realestate_amount,
                    COALESCE(lawyer_data.lawyer_count, 0) AS lawyer_count,
                    COALESCE(NULLIF(lawyer_data.order_amount, 0), 0) AS lawyer_amount,
                    COALESCE(ifa_data.ifa_count, 0) AS ifa_count,
                    COALESCE(NULLIF(ifa_data.order_amount, 0), 0) AS ifa_amount
                FROM users AS u
                INNER JOIN user_offices ON 
                    u.id = user_offices.user_id 
                    -- and user_offices.deleted_at is null
                INNER JOIN offices ON user_offices.office_id = offices.id
                INNER JOIN cities ON offices.city_id = cities.id 
                INNER JOIN office_departments as od ON offices.id = od.office_id ";
            
            // TAX DATA
            $sql .= "LEFT JOIN LATERAL (
                    SELECT 
                        COUNT(1) AS tax_count,
                        -- psoz.consultant_id,
                        SUM(psoz.interview_order_amount) as interview_order_amount
                    FROM interviews AS int
                    INNER JOIN office_departments od ON int.office_departments_id = od.id 
                    -- AND od.office_id = offices.id
                    INNER JOIN project_sozoku psoz ON int.id = psoz.interview_id
                    WHERE 
                        int.deleted_at IS NULL AND 
                        psoz.interview_order_date IS NOT NULL ";

            $bindings = [];
            if(!empty($year)) {
                $sql .= "AND EXTRACT(YEAR FROM psoz.interview_order_date) = ? ";
                $bindings[] = $year;
            }
            if(!empty($month)) {
                $sql .= "AND EXTRACT(MONTH FROM psoz.interview_order_date) = ? ";
                $bindings[] = $month;
            }
            $sql .= "AND int.result_type = " . InterviewResultType::ORDER_RECEIVED . " " ;
            $sql .= "AND psoz.consultant_id = u.id ";
            $sql .= "AND psoz.consultant_office_id = offices.id ";
            $sql .= "AND psoz.deleted_at IS NULL ";
            $sql .= ") AS tax_data ON true ";

            // LEGAL DATA
            $sql .= "LEFT JOIN LATERAL (
                SELECT 
                    COUNT(1) AS legal_count,
                    -- pleg.consultant_id,
                    SUM(pleg.interview_order_amount) as interview_order_amount
                FROM interviews AS int
                INNER JOIN office_departments od ON int.office_departments_id = od.id 
                -- AND od.office_id = offices.id
                INNER JOIN project_legal pleg ON int.id = pleg.interview_id
                WHERE 
                    int.deleted_at IS NULL AND 
                    pleg.interview_order_date IS NOT NULL ";
            if(!empty($year)) {
                $sql .= "AND EXTRACT(YEAR FROM pleg.interview_order_date) = ? ";
                $bindings[] = $year;
            }
            if(!empty($month)) {
                $sql .= "AND EXTRACT(MONTH FROM pleg.interview_order_date) = ? ";
                $bindings[] = $month;
            }
            $sql .= "AND int.result_type = " . InterviewResultType::ORDER_RECEIVED . " " ;
            $sql .= "AND pleg.consultant_id = u.id ";
            $sql .= "AND pleg.consultant_office_id = offices.id ";
            $sql .= "AND pleg.deleted_at IS NULL ";
            $sql .= ") AS legal_data ON true ";

            // INSURANCE DATA
            $sql .= "LEFT JOIN LATERAL (
                SELECT 
                    COUNT(1) AS insurance_count,
                    -- ins.manager_id,
                    SUM(ins_det.commission) as commission
                FROM project_insurance AS ins
                INNER JOIN office_departments od ON ins.office_departments_id = od.id 
                -- AND od.office_id = offices.id
                INNER JOIN project_insurance_details ins_det ON ins.id = ins_det.insurance_id
                WHERE 
                    ins.deleted_at IS NULL AND 
                    ins.requested_date IS NOT NULL ";
            if(!empty($year)) {
                $sql .= "AND EXTRACT(YEAR FROM ins.requested_date) = ? ";
                $bindings[] = $year;
            }
            if(!empty($month)) {
                $sql .= "AND EXTRACT(MONTH FROM ins.requested_date) = ? ";
                $bindings[] = $month;
            }
            $sql .= "AND ins_det.established = 1 ";
            $sql .= "AND ins.manager_id = u.id ";
            $sql .= "AND ins.manager_office_id = offices.id ";
            $sql .= ") AS insurance_data ON true ";

            // REAL ESTATE DATA
            $sql .= "LEFT JOIN LATERAL (
                SELECT 
                    COUNT(1) AS realestate_count,
                    -- pro.manager_id,
                    SUM(pro.order_amount) as order_amount
                FROM project_property AS pro
                INNER JOIN office_departments od ON pro.office_departments_id = od.id 
                -- AND od.office_id = offices.id
                WHERE 
                    pro.deleted_at IS NULL AND 
                    pro.requested_date IS NOT NULL ";
            if(!empty($year)) {
                $sql .= "AND EXTRACT(YEAR FROM pro.requested_date) = ? ";
                $bindings[] = $year;
            }
            if(!empty($month)) {
                $sql .= "AND EXTRACT(MONTH FROM pro.requested_date) = ? ";
                $bindings[] = $month;
            }
            $sql .= "AND pro.application_type = " . ProjectApplicationTypes::PROPERTY . " ";
            $sql .= "AND pro.manager_id = u.id ";
            $sql .= "AND pro.manager_office_id = offices.id ";
            $sql .= ") AS realestate_data ON true ";
            
            // LAWYER DATA
            $sql .= "LEFT JOIN LATERAL (
                SELECT 
                    COUNT(1) AS lawyer_count,
                    -- pro.manager_id,
                    SUM(pro.order_amount) as order_amount
                FROM project_property AS pro
                INNER JOIN office_departments od ON pro.office_departments_id = od.id 
                -- AND od.office_id = offices.id
                WHERE 
                    pro.deleted_at IS NULL AND 
                    pro.requested_date IS NOT NULL ";
            if(!empty($year)) {
                $sql .= "AND EXTRACT(YEAR FROM pro.requested_date) = ? ";
                $bindings[] = $year;
            }
            if(!empty($month)) {
                $sql .= "AND EXTRACT(MONTH FROM pro.requested_date) = ? ";
                $bindings[] = $month;
            }
            $sql .= "AND pro.application_type = " . ProjectApplicationTypes::LAWYER . " ";
            $sql .= "AND pro.manager_id = u.id ";
            $sql .= "AND pro.manager_office_id = offices.id ";
            $sql .= ") AS lawyer_data ON true ";

            // IFA DATA
            $sql .= "LEFT JOIN LATERAL (
                SELECT 
                    COUNT(1) AS ifa_count,
                    -- pro.manager_id,
                    SUM(pro.order_amount) as order_amount
                FROM project_property AS pro
                INNER JOIN office_departments od ON pro.office_departments_id = od.id 
                -- AND od.office_id = offices.id
                WHERE 
                    pro.deleted_at IS NULL AND 
                    pro.requested_date IS NOT NULL ";
            if(!empty($year)) {
                $sql .= "AND EXTRACT(YEAR FROM pro.requested_date) = ? ";
                $bindings[] = $year;
            }
            if(!empty($month)) {
                $sql .= "AND EXTRACT(MONTH FROM pro.requested_date) = ? ";
                $bindings[] = $month;
            }
            $sql .= "AND pro.application_type = " . ProjectApplicationTypes::IFA . " ";
            $sql .= "AND pro.manager_id = u.id ";
            $sql .= "AND pro.manager_office_id = offices.id ";
            $sql .= ") AS ifa_data ON true ";

            $sql .= "WHERE 1=1 ";
            $sql .= "AND u.department_id = " . $this->department_id . " ";
            $sql .= "AND u.id NOT IN " . $this->js_admin_ids_raw . " ";
            $sql .= "AND u.deleted_at IS NULL ";

            $sql .= "AND offices.status = 1 ";
            $sql .= "AND od.status = 1 ";
            $sql .= "AND od.department_id = " . $this->department_id . " ";
            if(!empty($city_id)) {
                $sql .= "AND offices.city_id = ? ";
                $bindings[] = $city_id;
            }
            if(!empty($office_id)) {
                $sql .= "AND offices.id = ? ";
                $bindings[] = $office_id;
            }
            if(!empty($user_ids) && count($user_ids)) {
                $placeholders = implode(', ', array_fill(0, count($user_ids), '?'));
                $sql .= "AND u.id IN ($placeholders) ";
                $bindings = array_merge($bindings, $user_ids);
            }
            $sql .= "GROUP BY u.id, offices.id, cities.id, user_offices.deleted_at, tax_data.tax_count, tax_data.interview_order_amount, legal_data.legal_count, legal_data.interview_order_amount, insurance_data.insurance_count, insurance_data.commission, realestate_data.realestate_count, realestate_data.order_amount, lawyer_data.lawyer_count, lawyer_data.order_amount, ifa_data.ifa_count, ifa_data.order_amount ";
            $sql .= "ORDER BY offices.id ASC, u.id ASC";

            // echo $sql; exit;
            $data = DB::select($sql, $bindings);
            // dd($data);
    
            return $data;
        } 
        catch (Exception $e) {
            $errorMessage = $e->getMessage();
            $errorFile = $e->getFile();
            $errorLine = $e->getLine();

            // Combine the error message with its location
            $errorDetails = [
                'message' => $errorMessage,
                'file' => $errorFile,
                'line' => $errorLine,
            ];

            // Assuming sendError is a method that can accept an array of error details
            return $this->sendError(__('something_went_wrong'), $errorDetails, 400);
        }
    }

    /**
     * Current loop record - $cur_rec
     * Existing record - $ex_rec
     */
    private function tab6Array($cur_rec, $ex_rec = [])
    {
        if(count($ex_rec)) {

            $_tax_count = $cur_rec->tax_count + $ex_rec['tax']['count'];
            $_tax_amount = intval(str_replace(",", "", $cur_rec->tax_amount)) + intval(str_replace(",", "", $ex_rec['tax']['amount']));

            $_legal_count = $cur_rec->legal_count + $ex_rec['legal']['count'];
            $_legal_amount = intval(str_replace(",", "", $cur_rec->legal_amount)) + intval(str_replace(",", "", $ex_rec['legal']['amount']));

            $_ins_count = $cur_rec->insurance_count + $ex_rec['insurance']['count'];
            $_ins_amount = intval(str_replace(",", "", $cur_rec->insurance_amount)) + intval(str_replace(",", "", $ex_rec['insurance']['amount']));

            $_realstate_count = $cur_rec->realestate_count + $ex_rec['realestate']['count'];
            $_realstate_amount = intval(str_replace(",", "", $cur_rec->realestate_amount)) + intval(str_replace(",", "", $ex_rec['realestate']['amount']));
            
            $_lawyer_count = $cur_rec->lawyer_count + $ex_rec['lawyer']['count'];
            $_lawyer_amount = intval(str_replace(",", "", $cur_rec->lawyer_amount)) + intval(str_replace(",", "", $ex_rec['lawyer']['amount']));

            $_ifa_count = $cur_rec->ifa_count + $ex_rec['ifa']['count'];
            $_ifa_amount = intval(str_replace(",", "", $cur_rec->ifa_amount)) + intval(str_replace(",", "", $ex_rec['ifa']['amount']));

            $data = [
                'tax' => [
                    'count'   => $_tax_count,
                    'amount'  => number_format($_tax_amount, 0, '.', ','),
                ],
                'legal' => [
                    'count'   => $_legal_count,
                    'amount'  => number_format($_legal_amount, 0, '.', ','),
                ],
                'insurance' => [
                    'count'   => $_ins_count,
                    'amount'  => number_format($_ins_amount, 0, '.', ','),
                ],
                'realestate' => [
                    'count'   => $_realstate_count,
                    'amount'  => number_format($_realstate_amount, 0, '.', ','),
                ],
                'lawyer' => [
                    'count'   => $_lawyer_count,
                    'amount'  => number_format($_lawyer_amount, 0, '.', ','),
                ],
                'ifa' => [
                    'count'   => $_ifa_count,
                    'amount'  => number_format($_ifa_amount, 0, '.', ','),
                ],
            ];
        }
        else {
            $data = [
                'tax' => [
                    'count'   => !empty($cur_rec->tax_count) ? $cur_rec->tax_count : 0,
                    'amount'  => number_format($cur_rec->tax_amount, 0, '.', ','),
                ],
                'legal' => [
                    'count'   => !empty($cur_rec->legal_count) ? $cur_rec->legal_count : 0,
                    'amount'  => number_format($cur_rec->legal_amount, 0, '.', ','),
                ],
                'insurance' => [
                    'count'   => !empty($cur_rec->insurance_count) ? $cur_rec->insurance_count : 0,
                    'amount'  => number_format($cur_rec->insurance_amount, 0, '.', ','),
                ],
                'realestate' => [
                    'count'   => !empty($cur_rec->realestate_count) ? $cur_rec->realestate_count : 0,
                    'amount'  => number_format($cur_rec->realestate_amount, 0, '.', ','),
                ],
                'lawyer' => [
                    'count'   => !empty($cur_rec->lawyer_count) ? $cur_rec->lawyer_count : 0,
                    'amount'  => number_format($cur_rec->lawyer_amount, 0, '.', ','),
                ],
                'ifa' => [
                    'count'   => !empty($cur_rec->ifa_count) ? $cur_rec->ifa_count : 0,
                    'amount'  => number_format($cur_rec->ifa_amount, 0, '.', ','),
                ],
            ];
        }

        return $data;
    }
   
    private function tab6DefaultArray()
    {   
        $data = [
            'tax' => [
                'count'   => 0,
                'amount'  => 0,
            ],
            'legal' => [
                'count'   => 0,
                'amount'  => 0,
            ],
            'insurance' => [
                'count'   => 0,
                'amount'  => 0,
            ],
            'realestate' => [
                'count'   => 0,
                'amount'  => 0,
            ],
            'lawyer' => [
                'count'   => 0,
                'amount'  => 0,
            ],
            'ifa' => [
                'count'   => 0,
                'amount'  => 0,
            ],
        ];

        return $data;
    }

    // INSURANCE TAB 7
    public function tab7(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'start_date' => 'required|date_format:Y-m',
                'end_date' => 'required|date_format:Y-m',
                'is_yearly_total' => 'in:1,0',
                'is_grand_total' => 'in:1,0',
                'search_type' => 'required|in:area,office,employee',
            ]);
        
            if ($validator->fails()) {
                $errors = $validator->errors();
                $error_array = [];
                if($errors->first('start_date')) {
                    $error_array['start_date'] = [$errors->first('start_date')];
                }
                if($errors->first('end_date')) {
                    $error_array['end_date'] = [$errors->first('end_date')];
                }
                if($errors->first('is_yearly_total')) {
                    $error_array['is_yearly_total'] = [$errors->first('is_yearly_total')];
                }
                if($errors->first('is_grand_total')) {
                    $error_array['is_grand_total'] = [$errors->first('is_grand_total')];
                }
                if($errors->first('search_type')) {
                    $error_array['search_type'] = [$errors->first('search_type')];
                }
                return $this->sendError(__('something_went_wrong'), $error_array, 422);
            }

            $search_type = $request->input('search_type', '');      // Possible values area, office, employee
            $is_yearly_total = $request->input('is_yearly_total', 0);
            $is_grand_total = $request->input('is_grand_total', 0);

            // Assuming $start_date and $end_date are in 'Y-m' format, e.g., '2023-12'
            $start_date = Carbon::createFromFormat('Y-m-d', $request->start_date.'-01');
            $end_date = Carbon::createFromFormat('Y-m-d', $request->end_date.'-01')->endOfMonth(); // Ensure the end date covers the end of the month

            $currentDate = $start_date->copy();
            $previousYear = $start_date->year;

            // RESPONSE DATA CODE
            $response_data = [];            
            // Initial run for Function
            $response_data[$currentDate->year][$currentDate->format('m')] = $this->tab7Data($request, $currentDate, 'month');
            // Move to the next month for the loop start
            $currentDate->addMonth();
            
            while ($currentDate->lessThanOrEqualTo($end_date)) {
                // Check if the year has changed, indicating the start of a new year
                if ($currentDate->year != $previousYear && $is_yearly_total == 1) {
                    // Run Function for total of year calculation before processing the new year
                    $response_data[$previousYear]['year_total'] = $this->tab7Data($request, $previousYear, 'year');
                    $previousYear = $currentDate->year;
                }

                // Run Function for the current month
                $response_data[$currentDate->year][$currentDate->format('m')] = $this->tab7Data($request, $currentDate, 'month');

                // Move to the next month
                $currentDate->addMonth();
            }

            // After completing the loop, check if Function needs to be called for the end date's year
            if ($end_date->year == $previousYear && $is_yearly_total == 1) {
                $response_data[$end_date->year]['year_total'] = $this->tab7Data($request, $end_date->year, 'year');
            }
            
            // For grand total row wise
            if($is_grand_total == 1) {
                $response_data['all_years']['grand_total'] = $this->tab7Data($request, $end_date->year, 'all_years');
            }
            // dd($response_data);

            // ALL DATA
            $manager_all_data = $others_all_data = $area_all_data = $offices_all_data = $total_column_data = [];
            foreach($response_data as $year => $record) {
                
                if(is_array($record)) {
                    foreach($record as $month => $worker_records) {

                        if(is_array($worker_records)) {
                            foreach($worker_records as $w_rec) {
                                $worker_check = $others_check = 0;
                                // ALL MANAGERs DATA
                                // if($w_rec->is_sales == 1 && $w_rec->status == 1 && empty($w_rec->office_deleted) && $search_type == 'employee') {
                                if($w_rec->is_sales == 1 && $w_rec->status == 1 && $search_type == 'employee') {
                                    $worker_check = 1;
                                    if(!isset($manager_all_data[$w_rec->id.'-'.$w_rec->office_id]) && $month != 'year_total' && $month != 'grand_total') {
                                        $manager_all_data[$w_rec->id.'-'.$w_rec->office_id] = [
                                            'Base' => $w_rec->office_name,
                                            'Manager' => $w_rec->mc_name,
                                            $this->months[$month] . ' ' . $year => $this->tab7Array($w_rec)
                                        ];
                                    }
                                    elseif(isset($manager_all_data[$w_rec->id.'-'.$w_rec->office_id]) && $month != 'year_total' && $month != 'grand_total') {
                                        $manager_all_data[$w_rec->id.'-'.$w_rec->office_id][$this->months[$month] . ' ' . $year] = $this->tab7Array($w_rec);
                                    }
                                    elseif(isset($manager_all_data[$w_rec->id.'-'.$w_rec->office_id]) && $month == 'year_total') {
                                        $manager_all_data[$w_rec->id.'-'.$w_rec->office_id][$year . ' Total'] = $this->tab7Array($w_rec);
                                    }
                                    elseif(isset($manager_all_data[$w_rec->id.'-'.$w_rec->office_id]) && $month == 'grand_total') {
                                        $manager_all_data[$w_rec->id.'-'.$w_rec->office_id]['Grand Total'] = $this->tab7Array($w_rec);
                                    }
                                }

                                // ALL OTHERS DATA
                                // if(($w_rec->is_sales == 0 || $w_rec->status == 0 || !empty($w_rec->office_deleted)) && $search_type == 'employee') {
                                if(($w_rec->is_sales == 0 || $w_rec->status == 0) && $search_type == 'employee') {
                                    $others_check = 1;
                                    if(!isset($others_all_data['others']) && $month != 'year_total' && $month != 'grand_total') {
                                        $others_all_data['others'] = [
                                            'Base' => '',
                                            'Manager' => __('others'),
                                            $this->months[$month] . ' ' . $year => $this->tab7Array($w_rec)
                                        ];
                                    }
                                    elseif(isset($others_all_data['others']) && isset($this->months[$month]) && !isset($others_all_data['others'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $others_all_data['others'][$this->months[$month] . ' ' . $year] = $this->tab7Array($w_rec);
                                    }
                                    elseif(isset($others_all_data['others']) && !isset($others_all_data['others'][$year . ' Total']) && $month == 'year_total') {
                                        $others_all_data['others'][$year . ' Total'] = $this->tab7Array($w_rec);
                                    }
                                    elseif(isset($others_all_data['others']) && !isset($others_all_data['others']['Grand Total']) && $month == 'grand_total') {
                                        $others_all_data['others']['Grand Total'] = $this->tab7Array($w_rec);
                                    }
                                    elseif(isset($others_all_data['others']) && isset($this->months[$month]) && isset($others_all_data['others'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $others_all_data['others'][$this->months[$month] . ' ' . $year];
                                        $others_all_data['others'][$this->months[$month] . ' ' . $year] = $this->tab7Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($others_all_data['others']) && isset($others_all_data['others'][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $others_all_data['others'][$year . ' Total'];
                                        $others_all_data['others'][$year . ' Total'] = $this->tab7Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($others_all_data['others']) && isset($others_all_data['others']['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $others_all_data['others']['Grand Total'];
                                        $others_all_data['others']['Grand Total'] = $this->tab7Array($w_rec, $existing_array);
                                    }
                                }

                                // IF THERE IS NO OTHERS DATA IN CURRENT ITERATION THEN ADD A DEFAULT DATA
                                if($worker_check == 1 && $others_check == 0) {
                                    if(!isset($others_all_data['others']) && $month != 'year_total' && $month != 'grand_total') {
                                        $others_all_data['others'] = [
                                            'Base' => '',
                                            'Manager' => __('others'),
                                            $this->months[$month] . ' ' . $year => $this->tab7DefaultArray()
                                        ];
                                    }
                                    elseif(isset($others_all_data['others']) && isset($this->months[$month]) && !isset($others_all_data['others'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $others_all_data['others'][$this->months[$month] . ' ' . $year] = $this->tab7DefaultArray();
                                    }
                                    elseif(isset($others_all_data['others']) && !isset($others_all_data['others'][$year . ' Total']) && $month == 'year_total') {
                                        $others_all_data['others'][$year . ' Total'] = $this->tab7DefaultArray();
                                    }
                                    elseif(isset($others_all_data['others']) && !isset($others_all_data['others']['Grand Total']) && $month == 'grand_total') {
                                        $others_all_data['others']['Grand Total'] = $this->tab7DefaultArray();
                                    }
                                }

                                // ALL AREA DATA
                                if($search_type == 'area') {
                                    if(!isset($area_all_data['city-' . $w_rec->city_id]) && $month != 'year_total' && $month != 'grand_total') {
                                        $area_all_data['city-' . $w_rec->city_id] = [
                                            'Base' => $w_rec->city_name,
                                            'Manager' => __('total'),
                                            $this->months[$month] . ' ' . $year => $this->tab7Array($w_rec)
                                        ];
                                    }
                                    elseif(isset($area_all_data['city-' . $w_rec->city_id]) && isset($this->months[$month]) && !isset($area_all_data['city-' . $w_rec->city_id][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $area_all_data['city-' . $w_rec->city_id][$this->months[$month] . ' ' . $year] = $this->tab7Array($w_rec);
                                    }
                                    elseif(isset($area_all_data['city-' . $w_rec->city_id]) && !isset($area_all_data['city-' . $w_rec->city_id][$year . ' Total']) && $month == 'year_total') {
                                        $area_all_data['city-' . $w_rec->city_id][$year . ' Total'] = $this->tab7Array($w_rec);
                                    }
                                    elseif(isset($area_all_data['city-' . $w_rec->city_id]) && !isset($area_all_data['city-' . $w_rec->city_id]['Grand Total']) && $month == 'grand_total') {
                                        $area_all_data['city-' . $w_rec->city_id]['Grand Total'] = $this->tab7Array($w_rec);
                                    }
                                    elseif(isset($area_all_data['city-' . $w_rec->city_id]) && isset($this->months[$month]) && isset($area_all_data['city-' . $w_rec->city_id][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $area_all_data['city-' . $w_rec->city_id][$this->months[$month] . ' ' . $year];
                                        $area_all_data['city-' . $w_rec->city_id][$this->months[$month] . ' ' . $year] = $this->tab7Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($area_all_data['city-' . $w_rec->city_id]) && isset($area_all_data['city-' . $w_rec->city_id][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $area_all_data['city-' . $w_rec->city_id][$year . ' Total'];
                                        $area_all_data['city-' . $w_rec->city_id][$year . ' Total'] = $this->tab7Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($area_all_data['city-' . $w_rec->city_id]) && isset($area_all_data['city-' . $w_rec->city_id]['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $area_all_data['city-' . $w_rec->city_id]['Grand Total'];
                                        $area_all_data['city-' . $w_rec->city_id]['Grand Total'] = $this->tab7Array($w_rec, $existing_array);
                                    }
                                }

                                // ALL OFFICES DATA
                                if($search_type == 'office') {
                                    if(!isset($offices_all_data['office-' . $w_rec->office_id]) && $month != 'year_total' && $month != 'grand_total') {
                                        $offices_all_data['office-' . $w_rec->office_id] = [
                                            'Base' => $w_rec->office_name,
                                            'Manager' => __('total'),
                                            $this->months[$month] . ' ' . $year => $this->tab7Array($w_rec)
                                        ];
                                    }
                                    elseif(isset($offices_all_data['office-' . $w_rec->office_id]) && isset($this->months[$month]) && !isset($offices_all_data['office-' . $w_rec->office_id][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $offices_all_data['office-' . $w_rec->office_id][$this->months[$month] . ' ' . $year] = $this->tab7Array($w_rec);
                                    }
                                    elseif(isset($offices_all_data['office-' . $w_rec->office_id]) && !isset($offices_all_data['office-' . $w_rec->office_id][$year . ' Total']) && $month == 'year_total') {
                                        $offices_all_data['office-' . $w_rec->office_id][$year . ' Total'] = $this->tab7Array($w_rec);
                                    }
                                    elseif(isset($offices_all_data['office-' . $w_rec->office_id]) && !isset($offices_all_data['office-' . $w_rec->office_id]['Grand Total']) && $month == 'grand_total') {
                                        $offices_all_data['office-' . $w_rec->office_id]['Grand Total'] = $this->tab7Array($w_rec);
                                    }
                                    elseif(isset($offices_all_data['office-' . $w_rec->office_id]) && isset($this->months[$month]) && isset($offices_all_data['office-' . $w_rec->office_id][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $offices_all_data['office-' . $w_rec->office_id][$this->months[$month] . ' ' . $year];
                                        $offices_all_data['office-' . $w_rec->office_id][$this->months[$month] . ' ' . $year] = $this->tab7Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($offices_all_data['office-' . $w_rec->office_id]) && isset($offices_all_data['office-' . $w_rec->office_id][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $offices_all_data['office-' . $w_rec->office_id][$year . ' Total'];
                                        $offices_all_data['office-' . $w_rec->office_id][$year . ' Total'] = $this->tab7Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($offices_all_data['office-' . $w_rec->office_id]) && isset($offices_all_data['office-' . $w_rec->office_id]['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $offices_all_data['office-' . $w_rec->office_id]['Grand Total'];
                                        $offices_all_data['office-' . $w_rec->office_id]['Grand Total'] = $this->tab7Array($w_rec, $existing_array);
                                    }
                                }

                                // ALL TOTAL DATA
                                if(!isset($total_column_data['column_wise_total']) && $month != 'year_total' && $month != 'grand_total') {
                                    $total_column_data['column_wise_total'] = [
                                        'Base' => __('grand_total'),
                                        'Manager' => '',
                                        $this->months[$month] . ' ' . $year => $this->tab7Array($w_rec)
                                    ];
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($this->months[$month]) && !isset($total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                    $total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year] = $this->tab7Array($w_rec);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && !isset($total_column_data['column_wise_total'][$year . ' Total']) && $month == 'year_total') {
                                    $total_column_data['column_wise_total'][$year . ' Total'] = $this->tab7Array($w_rec);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && !isset($total_column_data['column_wise_total']['Grand Total']) && $month == 'grand_total') {
                                    $total_column_data['column_wise_total']['Grand Total'] = $this->tab7Array($w_rec);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($this->months[$month]) && isset($total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                    $existing_array = $total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year];
                                    $total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year] = $this->tab7Array($w_rec, $existing_array);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($total_column_data['column_wise_total'][$year . ' Total']) && $month == 'year_total') {
                                    $existing_array = $total_column_data['column_wise_total'][$year . ' Total'];
                                    $total_column_data['column_wise_total'][$year . ' Total'] = $this->tab7Array($w_rec, $existing_array);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($total_column_data['column_wise_total']['Grand Total']) && $month == 'grand_total') {
                                    $existing_array = $total_column_data['column_wise_total']['Grand Total'];
                                    $total_column_data['column_wise_total']['Grand Total'] = $this->tab7Array($w_rec, $existing_array);
                                }
                            }
                        }
                    }
                }
            }
            // dd($worker_all_data, $teams_all_data);

            $data = [
                'search_type' => $search_type,
                'manager_data' => count($manager_all_data) ? $manager_all_data : (object)[],
                'others_data' => count($others_all_data) ? $others_all_data : (object)[],
                'area_data' => count($area_all_data) ? $area_all_data : (object)[],
                'office_data' => count($offices_all_data) ? $offices_all_data : (object)[],
                'grand_total' => count($total_column_data) ? $total_column_data : (object)[],
            ];
            
	        if (count($data)) {
	            return $this->sendResponse($data, __('record_found'));
	        }

	        return $this->sendResponse([], __('record_not_found'));
	    } 
	    catch (Exception $e) {
	        $errorMessage = $e->getMessage();
            $errorFile = $e->getFile();
            $errorLine = $e->getLine();

            // Combine the error message with its location
            $errorDetails = [
                'message' => $errorMessage,
                'file' => $errorFile,
                'line' => $errorLine,
            ];

            // Assuming sendError is a method that can accept an array of error details
            return $this->sendError(__('something_went_wrong'), $errorDetails, 400);
	    }
    }

    // INSURANCE TAB 7 - DATA
    private function tab7Data($request, $dateOrYear, $getDataBy = 'month') 
    {
        try {    
            $month = $year = '';
            if($getDataBy == 'month') {
                $month = $dateOrYear->format('m');
                $year = $dateOrYear->format('Y');
            }
            elseif($getDataBy == 'year') {
                $year = $dateOrYear;
            }

            $city_id = $request->input('city_id', '');
            $office_id = $request->input('office_id', '');
            $user_ids = $request->input('user_ids', '[]');
            $user_ids = is_array($user_ids) ? $user_ids : [];

            $sql = "SELECT DISTINCT 
                    u.id,
                    u.status,
                    u.is_sales,
                    user_offices.deleted_at AS office_deleted,
                    CONCAT(u.first_name, ' ', u.last_name) AS mc_name,
                    offices.id AS office_id,
                    offices.name AS office_name,
                    cities.id as city_id,
                    cities.name as city_name,
                    COALESCE(report.count, 0) AS count,
                    COALESCE(report.amount, 0) AS amount
                FROM users AS u
                INNER JOIN user_offices ON 
                    u.id = user_offices.user_id 
                    -- and user_offices.deleted_at is null
                INNER JOIN offices ON user_offices.office_id = offices.id
                INNER JOIN cities ON offices.city_id = cities.id
                INNER JOIN office_departments as od ON offices.id = od.office_id
                LEFT JOIN LATERAL (
                    SELECT 
                        COUNT(pid.id) AS count,
                        SUM(pid.commission) AS amount
                    FROM project_insurance AS pi
                    -- INNER JOIN office_departments od1 ON pi.office_departments_id = od1.id -- AND od1.office_id = offices.id
                    --INNER JOIN user_offices uo ON pi.manager_id = uo.user_id
                    --INNER JOIN offices o ON user_offices.office_id = o.id and o.id = offices.id
                    LEFT JOIN project_insurance_details pid ON pi.id = pid.insurance_id
                    WHERE
                        pi.manager_office_id = offices.id AND
                        pi.deleted_at IS NULL AND 
                        pid.deleted_at IS NULL AND 
                        pid.contract_date IS NOT NULL ";

            $bindings = [];
            if(!empty($year)) {
                $sql .= "AND EXTRACT(YEAR FROM pid.contract_date) = ? ";
                $bindings[] = $year;
            }
            if(!empty($month)) {
                $sql .= "AND EXTRACT(MONTH FROM pid.contract_date) = ? ";
                $bindings[] = $month;
            }
            
            $sql .= "AND pi.manager_id = user_offices.user_id ";
            $sql .= "AND pi.manager_id = u.id ";
            $sql .= ") AS report ON true ";
            $sql .= "WHERE 1=1 ";
            $sql .= "AND (u.department_id = " . $this->department_insurance_id . " OR u.department_id = " . $this->department_id . ") ";
            $sql .= "AND u.id NOT IN " . $this->js_admin_ids_raw . " ";
            $sql .= "AND u.deleted_at IS NULL ";

            $sql .= "AND offices.status = 1 ";
            $sql .= "AND od.status = 1 ";
            $sql .= "AND (od.department_id = " . $this->department_insurance_id . " OR od.department_id = " . $this->department_id . ") ";
            if(!empty($city_id)) {
                $sql .= "AND offices.city_id = ? ";
                $bindings[] = $city_id;
            }
            if(!empty($office_id)) {
                $sql .= "AND offices.id = ? ";
                $bindings[] = $office_id;
            }
            if(!empty($user_ids) && count($user_ids)) {
                $placeholders = implode(', ', array_fill(0, count($user_ids), '?'));
                $sql .= "AND u.id IN ($placeholders) ";
                $bindings = array_merge($bindings, $user_ids);
            }
            $sql .= "GROUP BY u.id, offices.id, cities.id, report.count, report.amount, user_offices.deleted_at ";
            $sql .= "ORDER BY offices.id ASC, u.id ASC";
            // echo $sql;exit;
            $data = DB::select($sql, $bindings);
            // dd($data);
    
            return $data;
        } 
        catch (Exception $e) {
            $errorMessage = $e->getMessage();
            $errorFile = $e->getFile();
            $errorLine = $e->getLine();

            // Combine the error message with its location
            $errorDetails = [
                'message' => $errorMessage,
                'file' => $errorFile,
                'line' => $errorLine,
            ];

            // Assuming sendError is a method that can accept an array of error details
            return $this->sendError(__('something_went_wrong'), $errorDetails, 400);
        }
    }

    /**
     * Current loop record - $cur_rec
     * Existing record - $ex_rec
     */
    private function tab7Array($cur_rec, $ex_rec = [])
    {
        if(count($ex_rec)) {

            $_count = $cur_rec->count + $ex_rec['count'];
            $_amount = intval(str_replace(",", "", $cur_rec->amount)) + intval(str_replace(",", "", $ex_rec['amount']));

            $data = [
                'count'     => $_count,
                'amount'   => number_format($_amount, 0, '.', ','),
            ];
        }
        else {
            $data = [
                'count'     => !empty($cur_rec->count) ? $cur_rec->count : 0,
                'amount'   => number_format($cur_rec->amount, 0, '.', ','),
            ];
        }

        return $data;
    }
   
    private function tab7DefaultArray()
    {
        $data = [
            'count'     => 0,
            'amount'   => 0,
        ];

        return $data;
    }

    // INSURANCE TAB 8
    public function tab8(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'start_date' => 'required|date_format:Y-m',
                'end_date' => 'required|date_format:Y-m',
                'is_yearly_total' => 'in:1,0',
                'is_grand_total' => 'in:1,0',
                'search_type' => 'required|in:area,office,employee',
            ]);
        
            if ($validator->fails()) {
                $errors = $validator->errors();
                $error_array = [];
                if($errors->first('start_date')) {
                    $error_array['start_date'] = [$errors->first('start_date')];
                }
                if($errors->first('end_date')) {
                    $error_array['end_date'] = [$errors->first('end_date')];
                }
                if($errors->first('is_yearly_total')) {
                    $error_array['is_yearly_total'] = [$errors->first('is_yearly_total')];
                }
                if($errors->first('is_grand_total')) {
                    $error_array['is_grand_total'] = [$errors->first('is_grand_total')];
                }
                if($errors->first('search_type')) {
                    $error_array['search_type'] = [$errors->first('search_type')];
                }
                return $this->sendError(__('something_went_wrong'), $error_array, 422);
            }

            $search_type = $request->input('search_type', '');      // Possible values area, office, employee
            $is_yearly_total = $request->input('is_yearly_total', 0);
            $is_grand_total = $request->input('is_grand_total', 0);

            // Assuming $start_date and $end_date are in 'Y-m' format, e.g., '2023-12'
            $start_date = Carbon::createFromFormat('Y-m-d', $request->start_date.'-01');
            $end_date = Carbon::createFromFormat('Y-m-d', $request->end_date.'-01')->endOfMonth(); // Ensure the end date covers the end of the month

            $currentDate = $start_date->copy();
            $previousYear = $start_date->year;

            // RESPONSE DATA CODE
            $response_data = [];            
            // Initial run for Function
            $response_data[$currentDate->year][$currentDate->format('m')] = $this->tab8Data($request, $currentDate, 'month');
            // Move to the next month for the loop start
            $currentDate->addMonth();
            
            while ($currentDate->lessThanOrEqualTo($end_date)) {
                // Check if the year has changed, indicating the start of a new year
                if ($currentDate->year != $previousYear && $is_yearly_total == 1) {
                    // Run Function for total of year calculation before processing the new year
                    $response_data[$previousYear]['year_total'] = $this->tab8Data($request, $previousYear, 'year');
                    $previousYear = $currentDate->year;
                }

                // Run Function for the current month
                $response_data[$currentDate->year][$currentDate->format('m')] = $this->tab8Data($request, $currentDate, 'month');

                // Move to the next month
                $currentDate->addMonth();
            }

            // After completing the loop, check if Function needs to be called for the end date's year
            if ($end_date->year == $previousYear && $is_yearly_total == 1) {
                $response_data[$end_date->year]['year_total'] = $this->tab8Data($request, $end_date->year, 'year');
            }
            
            // For grand total row wise
            if($is_grand_total == 1) {
                $response_data['all_years']['grand_total'] = $this->tab8Data($request, $end_date->year, 'all_years');
            }
            // dd($response_data);

            // ALL DATA
            $manager_all_data = $others_all_data = $area_all_data = $offices_all_data = $total_column_data = [];
            foreach($response_data as $year => $record) {
                
                if(is_array($record)) {
                    foreach($record as $month => $worker_records) {

                        if(is_array($worker_records)) {
                            foreach($worker_records as $w_rec) {
                                $worker_check = $others_check = 0;
                                // ALL MANAGERs DATA
                                // if($w_rec->is_sales == 1 && $w_rec->status == 1 && empty($w_rec->office_deleted) && $search_type == 'employee') {
                                if($w_rec->is_sales == 1 && $w_rec->status == 1 && $search_type == 'employee') {
                                    $worker_check = 1;
                                    if(!isset($manager_all_data[$w_rec->id.'-'.$w_rec->office_id]) && $month != 'year_total' && $month != 'grand_total') {
                                        $manager_all_data[$w_rec->id.'-'.$w_rec->office_id] = [
                                            'Base' => $w_rec->office_name,
                                            'Manager' => $w_rec->mc_name,
                                            $this->months[$month] . ' ' . $year => $this->tab8Array($w_rec)
                                        ];
                                    }
                                    elseif(isset($manager_all_data[$w_rec->id.'-'.$w_rec->office_id]) && $month != 'year_total' && $month != 'grand_total') {
                                        $manager_all_data[$w_rec->id.'-'.$w_rec->office_id][$this->months[$month] . ' ' . $year] = $this->tab8Array($w_rec);
                                    }
                                    elseif(isset($manager_all_data[$w_rec->id.'-'.$w_rec->office_id]) && $month == 'year_total') {
                                        $manager_all_data[$w_rec->id.'-'.$w_rec->office_id][$year . ' Total'] = $this->tab8Array($w_rec);
                                    }
                                    elseif(isset($manager_all_data[$w_rec->id.'-'.$w_rec->office_id]) && $month == 'grand_total') {
                                        $manager_all_data[$w_rec->id.'-'.$w_rec->office_id]['Grand Total'] = $this->tab8Array($w_rec);
                                    }
                                }

                                // ALL OTHERS DATA
                                // if(($w_rec->is_sales == 0 || $w_rec->status == 0 || !empty($w_rec->office_deleted)) && $search_type == 'employee') {
                                if(($w_rec->is_sales == 0 || $w_rec->status == 0) && $search_type == 'employee') {
                                    $others_check = 1;
                                    if(!isset($others_all_data['others']) && $month != 'year_total' && $month != 'grand_total') {
                                        $others_all_data['others'] = [
                                            'Base' => '',
                                            'Manager' => __('others'),
                                            $this->months[$month] . ' ' . $year => $this->tab8Array($w_rec)
                                        ];
                                    }
                                    elseif(isset($others_all_data['others']) && isset($this->months[$month]) && !isset($others_all_data['others'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $others_all_data['others'][$this->months[$month] . ' ' . $year] = $this->tab8Array($w_rec);
                                    }
                                    elseif(isset($others_all_data['others']) && !isset($others_all_data['others'][$year . ' Total']) && $month == 'year_total') {
                                        $others_all_data['others'][$year . ' Total'] = $this->tab8Array($w_rec);
                                    }
                                    elseif(isset($others_all_data['others']) && !isset($others_all_data['others']['Grand Total']) && $month == 'grand_total') {
                                        $others_all_data['others']['Grand Total'] = $this->tab8Array($w_rec);
                                    }
                                    elseif(isset($others_all_data['others']) && isset($this->months[$month]) && isset($others_all_data['others'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $others_all_data['others'][$this->months[$month] . ' ' . $year];
                                        $others_all_data['others'][$this->months[$month] . ' ' . $year] = $this->tab8Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($others_all_data['others']) && isset($others_all_data['others'][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $others_all_data['others'][$year . ' Total'];
                                        $others_all_data['others'][$year . ' Total'] = $this->tab8Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($others_all_data['others']) && isset($others_all_data['others']['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $others_all_data['others']['Grand Total'];
                                        $others_all_data['others']['Grand Total'] = $this->tab8Array($w_rec, $existing_array);
                                    }
                                }

                                // IF THERE IS NO OTHERS DATA IN CURRENT ITERATION THEN ADD A DEFAULT DATA
                                if($worker_check == 1 && $others_check == 0) {
                                    if(!isset($others_all_data['others']) && $month != 'year_total' && $month != 'grand_total') {
                                        $others_all_data['others'] = [
                                            'Base' => '',
                                            'Manager' => __('others'),
                                            $this->months[$month] . ' ' . $year => $this->tab8DefaultArray()
                                        ];
                                    }
                                    elseif(isset($others_all_data['others']) && isset($this->months[$month]) && !isset($others_all_data['others'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $others_all_data['others'][$this->months[$month] . ' ' . $year] = $this->tab8DefaultArray();
                                    }
                                    elseif(isset($others_all_data['others']) && !isset($others_all_data['others'][$year . ' Total']) && $month == 'year_total') {
                                        $others_all_data['others'][$year . ' Total'] = $this->tab8DefaultArray();
                                    }
                                    elseif(isset($others_all_data['others']) && !isset($others_all_data['others']['Grand Total']) && $month == 'grand_total') {
                                        $others_all_data['others']['Grand Total'] = $this->tab8DefaultArray();
                                    }
                                }

                                // ALL AREA DATA
                                if($search_type == 'area') {
                                    if(!isset($area_all_data['city-' . $w_rec->city_id]) && $month != 'year_total' && $month != 'grand_total') {
                                        $area_all_data['city-' . $w_rec->city_id] = [
                                            'Base' => $w_rec->city_name,
                                            'Manager' => __('total'),
                                            $this->months[$month] . ' ' . $year => $this->tab8Array($w_rec)
                                        ];
                                    }
                                    elseif(isset($area_all_data['city-' . $w_rec->city_id]) && isset($this->months[$month]) && !isset($area_all_data['city-' . $w_rec->city_id][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $area_all_data['city-' . $w_rec->city_id][$this->months[$month] . ' ' . $year] = $this->tab8Array($w_rec);
                                    }
                                    elseif(isset($area_all_data['city-' . $w_rec->city_id]) && !isset($area_all_data['city-' . $w_rec->city_id][$year . ' Total']) && $month == 'year_total') {
                                        $area_all_data['city-' . $w_rec->city_id][$year . ' Total'] = $this->tab8Array($w_rec);
                                    }
                                    elseif(isset($area_all_data['city-' . $w_rec->city_id]) && !isset($area_all_data['city-' . $w_rec->city_id]['Grand Total']) && $month == 'grand_total') {
                                        $area_all_data['city-' . $w_rec->city_id]['Grand Total'] = $this->tab8Array($w_rec);
                                    }
                                    elseif(isset($area_all_data['city-' . $w_rec->city_id]) && isset($this->months[$month]) && isset($area_all_data['city-' . $w_rec->city_id][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $area_all_data['city-' . $w_rec->city_id][$this->months[$month] . ' ' . $year];
                                        $area_all_data['city-' . $w_rec->city_id][$this->months[$month] . ' ' . $year] = $this->tab8Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($area_all_data['city-' . $w_rec->city_id]) && isset($area_all_data['city-' . $w_rec->city_id][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $area_all_data['city-' . $w_rec->city_id][$year . ' Total'];
                                        $area_all_data['city-' . $w_rec->city_id][$year . ' Total'] = $this->tab8Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($area_all_data['city-' . $w_rec->city_id]) && isset($area_all_data['city-' . $w_rec->city_id]['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $area_all_data['city-' . $w_rec->city_id]['Grand Total'];
                                        $area_all_data['city-' . $w_rec->city_id]['Grand Total'] = $this->tab8Array($w_rec, $existing_array);
                                    }
                                }

                                // ALL OFFICES DATA
                                if($search_type == 'office') {
                                    if(!isset($offices_all_data['office-' . $w_rec->office_id]) && $month != 'year_total' && $month != 'grand_total') {
                                        $offices_all_data['office-' . $w_rec->office_id] = [
                                            'Base' => $w_rec->office_name,
                                            'Manager' => __('total'),
                                            $this->months[$month] . ' ' . $year => $this->tab8Array($w_rec)
                                        ];
                                    }
                                    elseif(isset($offices_all_data['office-' . $w_rec->office_id]) && isset($this->months[$month]) && !isset($offices_all_data['office-' . $w_rec->office_id][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $offices_all_data['office-' . $w_rec->office_id][$this->months[$month] . ' ' . $year] = $this->tab8Array($w_rec);
                                    }
                                    elseif(isset($offices_all_data['office-' . $w_rec->office_id]) && !isset($offices_all_data['office-' . $w_rec->office_id][$year . ' Total']) && $month == 'year_total') {
                                        $offices_all_data['office-' . $w_rec->office_id][$year . ' Total'] = $this->tab8Array($w_rec);
                                    }
                                    elseif(isset($offices_all_data['office-' . $w_rec->office_id]) && !isset($offices_all_data['office-' . $w_rec->office_id]['Grand Total']) && $month == 'grand_total') {
                                        $offices_all_data['office-' . $w_rec->office_id]['Grand Total'] = $this->tab8Array($w_rec);
                                    }
                                    elseif(isset($offices_all_data['office-' . $w_rec->office_id]) && isset($this->months[$month]) && isset($offices_all_data['office-' . $w_rec->office_id][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $offices_all_data['office-' . $w_rec->office_id][$this->months[$month] . ' ' . $year];
                                        $offices_all_data['office-' . $w_rec->office_id][$this->months[$month] . ' ' . $year] = $this->tab8Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($offices_all_data['office-' . $w_rec->office_id]) && isset($offices_all_data['office-' . $w_rec->office_id][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $offices_all_data['office-' . $w_rec->office_id][$year . ' Total'];
                                        $offices_all_data['office-' . $w_rec->office_id][$year . ' Total'] = $this->tab8Array($w_rec, $existing_array);
                                    }
                                    elseif(isset($offices_all_data['office-' . $w_rec->office_id]) && isset($offices_all_data['office-' . $w_rec->office_id]['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $offices_all_data['office-' . $w_rec->office_id]['Grand Total'];
                                        $offices_all_data['office-' . $w_rec->office_id]['Grand Total'] = $this->tab8Array($w_rec, $existing_array);
                                    }
                                }

                                // ALL TOTAL DATA
                                if(!isset($total_column_data['column_wise_total']) && $month != 'year_total' && $month != 'grand_total') {
                                    $total_column_data['column_wise_total'] = [
                                        'Base' => __('grand_total'),
                                        'Manager' => '',
                                        $this->months[$month] . ' ' . $year => $this->tab8Array($w_rec)
                                    ];
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($this->months[$month]) && !isset($total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                    $total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year] = $this->tab8Array($w_rec);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && !isset($total_column_data['column_wise_total'][$year . ' Total']) && $month == 'year_total') {
                                    $total_column_data['column_wise_total'][$year . ' Total'] = $this->tab8Array($w_rec);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && !isset($total_column_data['column_wise_total']['Grand Total']) && $month == 'grand_total') {
                                    $total_column_data['column_wise_total']['Grand Total'] = $this->tab8Array($w_rec);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($this->months[$month]) && isset($total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                    $existing_array = $total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year];
                                    $total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year] = $this->tab8Array($w_rec, $existing_array);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($total_column_data['column_wise_total'][$year . ' Total']) && $month == 'year_total') {
                                    $existing_array = $total_column_data['column_wise_total'][$year . ' Total'];
                                    $total_column_data['column_wise_total'][$year . ' Total'] = $this->tab8Array($w_rec, $existing_array);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($total_column_data['column_wise_total']['Grand Total']) && $month == 'grand_total') {
                                    $existing_array = $total_column_data['column_wise_total']['Grand Total'];
                                    $total_column_data['column_wise_total']['Grand Total'] = $this->tab8Array($w_rec, $existing_array);
                                }
                            }
                        }
                    }
                }
            }
            // dd($worker_all_data, $teams_all_data);

            $data = [
                'search_type' => $search_type,
                'manager_data' => count($manager_all_data) ? $manager_all_data : (object)[],
                'others_data' => count($others_all_data) ? $others_all_data : (object)[],
                'area_data' => count($area_all_data) ? $area_all_data : (object)[],
                'office_data' => count($offices_all_data) ? $offices_all_data : (object)[],
                'grand_total' => count($total_column_data) ? $total_column_data : (object)[],
            ];
            
	        if (count($data)) {
	            return $this->sendResponse($data, __('record_found'));
	        }

	        return $this->sendResponse([], __('record_not_found'));
	    } 
	    catch (Exception $e) {
	        $errorMessage = $e->getMessage();
            $errorFile = $e->getFile();
            $errorLine = $e->getLine();

            // Combine the error message with its location
            $errorDetails = [
                'message' => $errorMessage,
                'file' => $errorFile,
                'line' => $errorLine,
            ];

            // Assuming sendError is a method that can accept an array of error details
            return $this->sendError(__('something_went_wrong'), $errorDetails, 400);
	    }
    }

    // INSURANCE TAB 8 - DATA
    private function tab8Data($request, $dateOrYear, $getDataBy = 'month') 
    {
        try {    
            $month = $year = '';
            if($getDataBy == 'month') {
                $month = $dateOrYear->format('m');
                $year = $dateOrYear->format('Y');
            }
            elseif($getDataBy == 'year') {
                $year = $dateOrYear;
            }

            $city_id = $request->input('city_id', '');
            $office_id = $request->input('office_id', '');
            $user_ids = $request->input('user_ids', '[]');
            $user_ids = is_array($user_ids) ? $user_ids : [];

            $sql = "SELECT DISTINCT 
                    u.id,
                    u.status,
                    u.is_sales,
                    user_offices.deleted_at AS office_deleted,
                    CONCAT(u.first_name, ' ', u.last_name) AS mc_name,
                    offices.id AS office_id,
                    offices.name AS office_name,
                    cities.id as city_id,
                    cities.name as city_name,
                    COALESCE(report.count, 0) AS count
                FROM users AS u
                INNER JOIN user_offices ON 
                    u.id = user_offices.user_id 
                    -- and user_offices.deleted_at is null
                INNER JOIN offices ON user_offices.office_id = offices.id
                INNER JOIN cities ON offices.city_id = cities.id
                INNER JOIN office_departments as od ON offices.id = od.office_id
                LEFT JOIN LATERAL (
                    SELECT 
                        COUNT(pi.id) AS count
                    FROM project_insurance AS pi
                    --INNER JOIN office_departments od ON pi.office_departments_id = od.id -- AND od.office_id = offices.id
                    WHERE 
                        pi.manager_office_id = offices.id AND
                        pi.deleted_at IS NULL AND
                        pi.requested_date IS NOT NULL ";

            $bindings = [];
            if(!empty($year)) {
                $sql .= "AND EXTRACT(YEAR FROM pi.requested_date) = ? ";
                $bindings[] = $year;
            }
            if(!empty($month)) {
                $sql .= "AND EXTRACT(MONTH FROM pi.requested_date) = ? ";
                $bindings[] = $month;
            }
            
            $sql .= "AND pi.manager_id = u.id ";
            $sql .= ") AS report ON true ";
            $sql .= "WHERE 1=1 ";
            $sql .= "AND (u.department_id = " . $this->department_insurance_id . " OR u.department_id = " . $this->department_id . ") ";
            $sql .= "AND u.id NOT IN " . $this->js_admin_ids_raw . " ";
            $sql .= "AND u.deleted_at IS NULL ";

            $sql .= "AND offices.status = 1 ";
            $sql .= "AND od.status = 1 ";
            $sql .= "AND (od.department_id = " . $this->department_insurance_id . " OR od.department_id = " . $this->department_id . ") ";
            if(!empty($city_id)) {
                $sql .= "AND offices.city_id = ? ";
                $bindings[] = $city_id;
            }
            if(!empty($office_id)) {
                $sql .= "AND offices.id = ? ";
                $bindings[] = $office_id;
            }
            if(!empty($user_ids) && count($user_ids)) {
                $placeholders = implode(', ', array_fill(0, count($user_ids), '?'));
                $sql .= "AND u.id IN ($placeholders) ";
                $bindings = array_merge($bindings, $user_ids);
            }
            $sql .= "GROUP BY u.id, offices.id, cities.id, report.count, user_offices.deleted_at ";
            $sql .= "ORDER BY offices.id ASC, u.id ASC";
            // echo $sql;exit;
            $data = DB::select($sql, $bindings);
            // dd($data);
    
            return $data;
        } 
        catch (Exception $e) {
            $errorMessage = $e->getMessage();
            $errorFile = $e->getFile();
            $errorLine = $e->getLine();

            // Combine the error message with its location
            $errorDetails = [
                'message' => $errorMessage,
                'file' => $errorFile,
                'line' => $errorLine,
            ];

            // Assuming sendError is a method that can accept an array of error details
            return $this->sendError(__('something_went_wrong'), $errorDetails, 400);
        }
    }

    /**
     * Current loop record - $cur_rec
     * Existing record - $ex_rec
     */
    private function tab8Array($cur_rec, $ex_rec = [])
    {
        if(count($ex_rec)) {

            $_count = $cur_rec->count + $ex_rec['count'];

            $data = [
                'count'     => $_count,
            ];
        }
        else {
            $data = [
                'count'     => !empty($cur_rec->count) ? $cur_rec->count : 0,
            ];
        }

        return $data;
    }
    
    private function tab8DefaultArray()
    {
        $data = [
            'count' => 0,
        ];

        return $data;
    }

    // INSURANCE TAB 9
    public function tab9(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'start_date' => 'required|date_format:Y-m',
                'end_date' => 'required|date_format:Y-m',
                'is_yearly_total' => 'in:1,0',
                'is_grand_total' => 'in:1,0',
                'search_type' => 'required|in:area,office,result_type',
            ]);
        
            if ($validator->fails()) {
                $errors = $validator->errors();
                $error_array = [];
                if($errors->first('start_date')) {
                    $error_array['start_date'] = [$errors->first('start_date')];
                }
                if($errors->first('end_date')) {
                    $error_array['end_date'] = [$errors->first('end_date')];
                }
                if($errors->first('is_yearly_total')) {
                    $error_array['is_yearly_total'] = [$errors->first('is_yearly_total')];
                }
                if($errors->first('is_grand_total')) {
                    $error_array['is_grand_total'] = [$errors->first('is_grand_total')];
                }
                return $this->sendError(__('something_went_wrong'), $error_array, 422);
            }

            $search_type = $request->input('search_type', '');      // Possible values area, office, employee
            $is_yearly_total = $request->input('is_yearly_total', 0);
            $is_grand_total = $request->input('is_grand_total', 0);

            // Assuming $start_date and $end_date are in 'Y-m' format, e.g., '2023-12'
            $start_date = Carbon::createFromFormat('Y-m-d', $request->start_date.'-01');
            $end_date = Carbon::createFromFormat('Y-m-d', $request->end_date.'-01')->endOfMonth(); // Ensure the end date covers the end of the month

            $currentDate = $start_date->copy();
            $previousYear = $start_date->year;

            // RESPONSE DATA CODE
            $response_data = [];            
            // Initial run for Function
            $response_data[$currentDate->year][$currentDate->format('m')] = $this->tab9Data($request, $currentDate, 'month');
            // Move to the next month for the loop start
            $currentDate->addMonth();
            
            while ($currentDate->lessThanOrEqualTo($end_date)) {
                // Check if the year has changed, indicating the start of a new year
                if ($currentDate->year != $previousYear && $is_yearly_total == 1) {
                    // Run Function for total of year calculation before processing the new year
                    $response_data[$previousYear]['year_total'] = $this->tab9Data($request, $previousYear, 'year');
                    $previousYear = $currentDate->year;
                }

                // Run Function for the current month
                $response_data[$currentDate->year][$currentDate->format('m')] = $this->tab9Data($request, $currentDate, 'month');

                // Move to the next month
                $currentDate->addMonth();
            }

            // After completing the loop, check if Function needs to be called for the end date's year
            if ($end_date->year == $previousYear && $is_yearly_total == 1) {
                $response_data[$end_date->year]['year_total'] = $this->tab9Data($request, $end_date->year, 'year');
            }
            
            // For grand total row wise
            if($is_grand_total == 1) {
                $response_data['all_years']['grand_total'] = $this->tab9Data($request, $end_date->year, 'all_years');
            }
            $response_data = json_decode(json_encode($response_data), true);
            // dd($response_data);

            $result_type_all_data = $area_all_data = $offices_all_data = $total_column_data = [];
            foreach($response_data as $year => $record) {
                
                if(is_array($record)) {
                    foreach($record as $month => $ref_records) {

                        if(is_array($ref_records)) {
                            foreach($ref_records as $ref_rec) {

                                // ALL RESULT TYPE'S DATA
                                if($search_type == 'result_type'){
                                    if(!isset($result_type_all_data[$ref_rec['result_type_id'].'-'.$ref_rec['office_id']]) && $month != 'year_total' && $month != 'grand_total') {
                                        $result_type_all_data[$ref_rec['result_type_id'].'-'.$ref_rec['office_id']] = [
                                            'Base' => $ref_rec['office_name'],
                                            'ResultType' => $ref_rec['result_type'],
                                            $this->months[$month] . ' ' . $year => $this->tab9Array($ref_rec)
                                        ];
                                    }
                                    elseif(isset($result_type_all_data[$ref_rec['result_type_id'].'-'.$ref_rec['office_id']]) && $month != 'year_total' && $month != 'grand_total') {
                                        $result_type_all_data[$ref_rec['result_type_id'].'-'.$ref_rec['office_id']][$this->months[$month] . ' ' . $year] = $this->tab9Array($ref_rec);
                                    }
                                    elseif(isset($result_type_all_data[$ref_rec['result_type_id'].'-'.$ref_rec['office_id']]) && $month == 'year_total') {
                                        $result_type_all_data[$ref_rec['result_type_id'].'-'.$ref_rec['office_id']][$year . ' Total'] = $this->tab9Array($ref_rec);
                                    }
                                    elseif(isset($result_type_all_data[$ref_rec['result_type_id'].'-'.$ref_rec['office_id']]) && $month == 'grand_total') {
                                        $result_type_all_data[$ref_rec['result_type_id'].'-'.$ref_rec['office_id']]['Grand Total'] = $this->tab9Array($ref_rec);
                                    }
                                }
                                
                                // ALL AREA DATA
                                if($search_type == 'area') {
                                    if(!isset($area_all_data['city-'.$ref_rec['city_id']]) && $month != 'year_total' && $month != 'grand_total') {
                                        $area_all_data['city-'.$ref_rec['city_id']] = [
                                            'Base' => $ref_rec['city_name'],
                                            'ResultType' => __('total'),
                                            $this->months[$month] . ' ' . $year => $this->tab9Array($ref_rec)
                                        ];
                                    }
                                    elseif(isset($area_all_data['city-'.$ref_rec['city_id']]) && isset($this->months[$month]) && !isset($area_all_data['city-'.$ref_rec['city_id']][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $area_all_data['city-'.$ref_rec['city_id']][$this->months[$month] . ' ' . $year] = $this->tab9Array($ref_rec);
                                    }
                                    elseif(isset($area_all_data['city-'.$ref_rec['city_id']]) && !isset($area_all_data['city-'.$ref_rec['city_id']][$year . ' Total']) && $month == 'year_total') {
                                        $area_all_data['city-'.$ref_rec['city_id']][$year . ' Total'] = $this->tab9Array($ref_rec);
                                    }
                                    elseif(isset($area_all_data['city-'.$ref_rec['city_id']]) && !isset($area_all_data['city-'.$ref_rec['city_id']]['Grand Total']) && $month == 'grand_total') {
                                        $area_all_data['city-'.$ref_rec['city_id']]['Grand Total'] = $this->tab9Array($ref_rec);
                                    }
                                    elseif(isset($area_all_data['city-'.$ref_rec['city_id']]) && isset($this->months[$month]) && isset($area_all_data['city-'.$ref_rec['city_id']][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $area_all_data['city-'.$ref_rec['city_id']][$this->months[$month] . ' ' . $year];                                    
                                        $area_all_data['city-'.$ref_rec['city_id']][$this->months[$month] . ' ' . $year] = $this->tab9Array($ref_rec, $existing_array);
                                    }
                                    elseif(isset($area_all_data['city-'.$ref_rec['city_id']]) && isset($area_all_data['city-'.$ref_rec['city_id']][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $area_all_data['city-'.$ref_rec['city_id']][$year . ' Total'];
                                        $area_all_data['city-'.$ref_rec['city_id']][$year . ' Total'] = $this->tab9Array($ref_rec, $existing_array);
                                    }
                                    elseif(isset($area_all_data['city-'.$ref_rec['city_id']]) && isset($area_all_data['city-'.$ref_rec['city_id']]['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $area_all_data['city-'.$ref_rec['city_id']]['Grand Total'];
                                        $area_all_data['city-'.$ref_rec['city_id']]['Grand Total'] = $this->tab9Array($ref_rec, $existing_array);
                                    }
                                }

                                // ALL OFFICES SUM DATA
                                if($search_type == 'office') {
                                    if(!isset($offices_all_data['office - ' . $ref_rec['office_id']]) && $month != 'year_total' && $month != 'grand_total') {
                                        $offices_all_data['office - ' . $ref_rec['office_id']] = [
                                            'Base' => $ref_rec['office_name'],
                                            'ResultType' => __('total'),
                                            $this->months[$month] . ' ' . $year => $this->tab9Array($ref_rec)
                                        ];
                                    }
                                    elseif(isset($offices_all_data['office - ' . $ref_rec['office_id']]) && isset($this->months[$month]) && !isset($offices_all_data['office - ' . $ref_rec['office_id']][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                        $offices_all_data['office - ' . $ref_rec['office_id']][$this->months[$month] . ' ' . $year] = $this->tab9Array($ref_rec);
                                    }
                                    elseif(isset($offices_all_data['office - ' . $ref_rec['office_id']]) && !isset($offices_all_data['office - ' . $ref_rec['office_id']][$year . ' Total']) && $month == 'year_total') {
                                        $offices_all_data['office - ' . $ref_rec['office_id']][$year . ' Total'] = $this->tab9Array($ref_rec);
                                    }
                                    elseif(isset($offices_all_data['office - ' . $ref_rec['office_id']]) && !isset($offices_all_data['office - ' . $ref_rec['office_id']]['Grand Total']) && $month == 'grand_total') {
                                        $offices_all_data['office - ' . $ref_rec['office_id']]['Grand Total'] = $this->tab9Array($ref_rec);
                                    }
                                    elseif(isset($offices_all_data['office - ' . $ref_rec['office_id']]) && isset($this->months[$month]) && isset($offices_all_data['office - ' . $ref_rec['office_id']][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                        $existing_array = $offices_all_data['office - ' . $ref_rec['office_id']][$this->months[$month] . ' ' . $year];
                                        $offices_all_data['office - ' . $ref_rec['office_id']][$this->months[$month] . ' ' . $year] = $this->tab9Array($ref_rec, $existing_array);
                                    }
                                    elseif(isset($offices_all_data['office - ' . $ref_rec['office_id']]) && isset($offices_all_data['office - ' . $ref_rec['office_id']][$year . ' Total']) && $month == 'year_total') {
                                        $existing_array = $offices_all_data['office - ' . $ref_rec['office_id']][$year . ' Total'];
                                        $offices_all_data['office - ' . $ref_rec['office_id']][$year . ' Total'] = $this->tab9Array($ref_rec, $existing_array);
                                    }
                                    elseif(isset($offices_all_data['office - ' . $ref_rec['office_id']]) && isset($offices_all_data['office - ' . $ref_rec['office_id']]['Grand Total']) && $month == 'grand_total') {
                                        $existing_array = $offices_all_data['office - ' . $ref_rec['office_id']]['Grand Total'];
                                        $offices_all_data['office - ' . $ref_rec['office_id']]['Grand Total'] = $this->tab9Array($ref_rec, $existing_array);
                                    }
                                }

                                // ALL TOTAL DATA
                                if(!isset($total_column_data['column_wise_total']) && $month != 'year_total' && $month != 'grand_total') {
                                    $total_column_data['column_wise_total'] = [
                                        'Base' => __('grand_total'),
                                        'ResultType' => '',
                                        $this->months[$month] . ' ' . $year => $this->tab9Array($ref_rec)
                                    ];
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($this->months[$month]) && !isset($total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {                        
                                    $total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year] = $this->tab9Array($ref_rec);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && !isset($total_column_data['column_wise_total'][$year . ' Total']) && $month == 'year_total') {
                                    $total_column_data['column_wise_total'][$year . ' Total'] = $this->tab9Array($ref_rec);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && !isset($total_column_data['column_wise_total']['Grand Total']) && $month == 'grand_total') {
                                    $total_column_data['column_wise_total']['Grand Total'] = $this->tab9Array($ref_rec);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($this->months[$month]) && isset($total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year]) && $month != 'year_total' && $month != 'grand_total') {
                                    $existing_array = $total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year];                                    
                                    $total_column_data['column_wise_total'][$this->months[$month] . ' ' . $year] = $this->tab9Array($ref_rec, $existing_array);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($total_column_data['column_wise_total'][$year . ' Total']) && $month == 'year_total') {
                                    $existing_array = $total_column_data['column_wise_total'][$year . ' Total'];
                                    $total_column_data['column_wise_total'][$year . ' Total'] = $this->tab9Array($ref_rec, $existing_array);
                                }
                                elseif(isset($total_column_data['column_wise_total']) && isset($total_column_data['column_wise_total']['Grand Total']) && $month == 'grand_total') {
                                    $existing_array = $total_column_data['column_wise_total']['Grand Total'];
                                    $total_column_data['column_wise_total']['Grand Total'] = $this->tab9Array($ref_rec, $existing_array);
                                }
                            }
                        }
                    }
                }
            }

            $data = [
                'search_type' => $search_type,
                'result_type_data' => count($result_type_all_data) ? $result_type_all_data : (object)[],
                'area_data' => count($area_all_data) ? $area_all_data : (object)[],
                'office_data' => count($offices_all_data) ? $offices_all_data : (object)[],
                'grand_total' => count($total_column_data) ? $total_column_data : (object)[],
            ];
            
	        if (count($data)) {
	            return $this->sendResponse($data, __('record_found'));
	        }

	        return $this->sendResponse([], __('record_not_found'));
	    } 
	    catch (Exception $e) {
	        $errorMessage = $e->getMessage();
            $errorFile = $e->getFile();
            $errorLine = $e->getLine();

            // Combine the error message with its location
            $errorDetails = [
                'message' => $errorMessage,
                'file' => $errorFile,
                'line' => $errorLine,
            ];

            // Assuming sendError is a method that can accept an array of error details
            return $this->sendError(__('something_went_wrong'), $errorDetails, 400);
	    }
    }

    // INSURANCE TAB 9 - DATA
    private function tab9Data($request, $dateOrYear, $getDataBy = 'month') 
    {
        try {    
            $month = $year = '';
            if($getDataBy == 'month') {
                $month = $dateOrYear->format('m');
                $year = $dateOrYear->format('Y');
            }
            elseif($getDataBy == 'year') {
                $year = $dateOrYear;
            }

            $city_id = $request->input('city_id', '');
            $office_id = $request->input('office_id', '');
            $result_type_ids = $request->input('result_type_ids', '[]');
            $result_type_ids = is_array($result_type_ids) ? $result_type_ids : [];

            $result_types = InsuranceResultType::GetByValue;
    
            $sql = "SELECT 
                        DISTINCT
                        offices.id as office_id, 
                        offices.name as office_name,
                        cities.id as city_id,
                        cities.name as city_name,
                        result.type as result_type,
                        result.typeid as result_type_id,
                        COALESCE(report.count, 0) AS count,
                        COALESCE(report.amount, 0) AS amount ";

            $sql .= "FROM 
                        offices 
                    INNER JOIN 
                        cities on offices.city_id = cities.id 
                    INNER JOIN 
                        office_departments as od on offices.id = od.office_id ";
            
            $sql .= "CROSS JOIN ( ";
            foreach($result_types as $k => $val) {
                if ($k === array_key_first($result_types)) {
                    $sql .= "SELECT '" . $val . "' as type, " . $k . " as typeid ";
                }
                else {
                    $sql .= "UNION ALL SELECT '" . $val . "' as type, " . $k . " as typeid ";
                }
            }
            $sql .= " ) as result ";
            $sql .= "LEFT JOIN LATERAL (
                SELECT 
                    COUNT(pid.id) AS count,
                    SUM(pid.commission) AS amount
                FROM 
                    project_insurance AS pi
                -- INNER JOIN 
                --     office_departments od ON pi.office_departments_id = od.id 
                --     AND od.office_id = offices.id
                LEFT JOIN 
                    project_insurance_details pid ON pi.id = pid.insurance_id and pid.result_type = result.typeid
                WHERE 
                    pi.manager_office_id = offices.id AND   
                    pi.deleted_at IS NULL AND
                    pid.contract_date IS NOT NULL AND 
                    pid.deleted_at IS NULL ";

            $bindings = [];
            if(!empty($year)) {
                $sql .= "AND EXTRACT(YEAR FROM pid.contract_date) = ? ";
                $bindings[] = $year;
            }
            if(!empty($month)) {
                $sql .= "AND EXTRACT(MONTH FROM pid.contract_date) = ? ";
                $bindings[] = $month;
            }
            // $sql .= "AND pid.result_type = result.typeid ";
            $sql .= ") AS report ON true ";
            $sql .= "WHERE 1=1 ";
            $sql .= "AND offices.status = 1 ";
            $sql .= "AND offices.deleted_at IS NULL ";
            $sql .= "AND od.status = 1 ";
            $sql .= "AND (od.department_id = " . $this->department_insurance_id . " OR od.department_id = " . $this->department_id . ") ";
            
            if(!empty($city_id)) {
                $sql .= "AND offices.city_id = ? ";
                $bindings[] = $city_id;
            }
            if(!empty($office_id)) {
                $sql .= "AND offices.id = ? ";
                $bindings[] = $office_id;
            }
            if (count($result_type_ids)) {
                // Create a list of placeholders
                $placeholders = implode(',', array_fill(0, count($result_type_ids), '?'));
            
                // Append the prepared statement placeholders to the SQL string
                $sql .= "AND result.typeid IN ($placeholders) ";
            
                // Merge the array of IDs into your existing bindings array
                $bindings = array_merge($bindings, $result_type_ids);
            }

            $sql .= "ORDER BY offices.id, result.typeid";
            // echo $sql; exit;
            $data = DB::select($sql, $bindings);
            // dd($data);
    
            return $data;
        } 
        catch (Exception $e) {
            $errorMessage = $e->getMessage();
            $errorFile = $e->getFile();
            $errorLine = $e->getLine();

            // Combine the error message with its location
            $errorDetails = [
                'message' => $errorMessage,
                'file' => $errorFile,
                'line' => $errorLine,
            ];

            // Assuming sendError is a method that can accept an array of error details
            return $this->sendError(__('something_went_wrong'), $errorDetails, 400);
        }
    }

    /**
     * Current loop record - $cur_rec
     * Existing record - $ex_rec
     */
    private function tab9Array($cur_rec, $ex_rec = [])
    {
        if(count($ex_rec)) {

            $_count = $cur_rec['count'] + $ex_rec['count'];
            $_amount = str_replace(",", "", $cur_rec['amount']) + str_replace(",", "", $ex_rec['amount']);

            $data = [
                'count'   => $_count,
                'amount'  => number_format($_amount, 0, '.', ','),
            ];
        }
        else {
            $data = [
                'count'   => $cur_rec['count'],
                'amount'  => number_format($cur_rec['amount'], 0, '.', ','),
            ];
        }

        return $data;
    }

    private function division($dividend, $divisor)
    {
        // $result = ($divisor != 0) ? sprintf("%.1f", ($dividend / $divisor) * 100) . '%' : '0%';

        // // FORMAT RESULT TO REMOVE .0 IF IT IS 0.0% OR 100.0%
        // if ($result == '0.0%') {
        //     $result = '0%';
        // } elseif ($result == '100.0%') {
        //     $result = '100%';
        // }

        // return $result;


        if ($divisor != 0) {
            $result = ($dividend / $divisor) * 100;
            $formattedResult = sprintf("%.1f", $result) . '%';
            $formattedResult = preg_replace('/\.0%$/', '%', $formattedResult);
        } else {
            $formattedResult = '0%';
        }
        return $formattedResult;
    }

    // DASHBOARD TEST API
    public function test(Request $request)
    {
        try {
        	
            $sort_type 	 = 'desc';
            $sort_column = 'i.created_at';

            // Sample dynamic header structure for months and their sub-columns
            $headers = [
                'Base',
                'MC',
                [
                    'title' => 'December 2023', // Parent column
                    'subcolumns' => [ // Sub-columns
                        'interview' => [
                            'number_of_interviews',
                            'number_of_deals',
                            'contract_rate',
                            'estimated_amount',
                            'order_amount'
                        ],
                        'power_reception' => [
                            'number_of_power_received',
                            'number_of_appointments',
                            'attraction_rate',
                            'rank'
                        ]
                    ]
                ],
                [
                    'title' => '2023 Total', // Parent column
                    'subcolumns' => [ // Sub-columns
                        'interview' => [
                            'number_of_interviews',
                            'number_of_deals',
                            'contract_rate',
                            'estimated_amount',
                            'order_amount'
                        ],
                        'power_reception' => [
                            'number_of_power_received',
                            'number_of_appointments',
                            'attraction_rate',
                            'rank'
                        ]
                    ]
                ],
                [
                    'title' => 'January 2024', // Parent column
                    'subcolumns' => [ // Sub-columns
                        'interview' => [
                            'number_of_interviews',
                            'number_of_deals',
                            'contract_rate',
                            'estimated_amount',
                            'order_amount'
                        ],
                        'power_reception' => [
                            'number_of_power_received',
                            'number_of_appointments',
                            'attraction_rate',
                            'rank'
                        ]
                    ]
                ],
                [
                    'title' => 'February 2024', // Parent column
                    'subcolumns' => [ // Sub-columns
                        'interview' => [
                            'number_of_interviews',
                            'number_of_deals',
                            'contract_rate',
                            'estimated_amount',
                            'order_amount'
                        ],
                        'power_reception' => [
                            'number_of_power_received',
                            'number_of_appointments',
                            'attraction_rate',
                            'rank'
                        ]
                    ]
                ],
                [
                    'title' => '2024 Total', // Parent column
                    'subcolumns' => [ // Sub-columns
                        'interview' => [
                            'number_of_interviews',
                            'number_of_deals',
                            'contract_rate',
                            'estimated_amount',
                            'order_amount'
                        ],
                        'power_reception' => [
                            'number_of_power_received',
                            'number_of_appointments',
                            'attraction_rate',
                            'rank'
                        ]
                    ]
                ],
                
            ];

            // Sample data array, structured according to headers
            $mc_data = [
                [
                    'Base' => 'Ginza',
                    'MC' => 'Yoshimasa Shimakura (Ginza)',
                    'December 2023' => [ // Matching parent column
                        'interview' => [
                            'number_of_interviews' => 0,
                            'number_of_deals' => 0,
                            'contract_rate' => '0%',
                            'estimated_amount' => 0,
                            'order_amount' => 0
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 0,
                            'number_of_appointments' => 0,
                            'attraction_rate' => '0%',
                            'rank' => 1
                        ]
                    ],
                    '2023 Total' => [
                        'interview' => [
                            'number_of_interviews' => 12,
                            'number_of_deals' => 10,
                            'contract_rate' => '56%',
                            'estimated_amount' => 25000,
                            'order_amount' => 22210
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 297,
                            'number_of_appointments' => 91,
                            'attraction_rate' => '30.6%',
                            'rank' => 36
                        ]
                    ],
                    'January 2024' => [ // Matching parent column
                        'interview' => [
                            'number_of_interviews' => 5,
                            'number_of_deals' => 3,
                            'contract_rate' => '25%',
                            'estimated_amount' => 25892,
                            'order_amount' => 22589
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 0,
                            'number_of_appointments' => 0,
                            'attraction_rate' => '0%',
                            'rank' => 1
                        ]
                    ],
                    'Febuary 2024' => [ // Matching parent column
                        'interview' => [
                            'number_of_interviews' => 45,
                            'number_of_deals' => 15,
                            'contract_rate' => '58%',
                            'estimated_amount' => 25479,
                            'order_amount' => 23000
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 8,
                            'number_of_appointments' => 7,
                            'attraction_rate' => '2.58%',
                            'rank' => 4
                        ]
                    ],
                    '2024 Total' => [ // Matching parent column
                        'interview' => [
                            'number_of_interviews' => 270,
                            'number_of_deals' => 159,
                            'contract_rate' => '78%',
                            'estimated_amount' => 263000,
                            'order_amount' => 199000
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 5,
                            'number_of_appointments' => 7,
                            'attraction_rate' => '5.6%',
                            'rank' => 4
                        ]
                    ],
                ],
                [
                    'Base' => 'Ginza',
                    'MC' => 'Yasuhiro Nishii',
                    'December 2023' => [ // Matching parent column
                        'interview' => [
                            'number_of_interviews' => 89,
                            'number_of_deals' => 45,
                            'contract_rate' => '58%',
                            'estimated_amount' => 2684,
                            'order_amount' => 2589
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 4,
                            'number_of_appointments' => 1,
                            'attraction_rate' => '3%',
                            'rank' => 89
                        ]
                    ],
                    '2023 Total' => [
                        'interview' => [
                            'number_of_interviews' => 45,
                            'number_of_deals' => 47,
                            'contract_rate' => '59%',
                            'estimated_amount' => 27000,
                            'order_amount' => 45870
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 369,
                            'number_of_appointments' => 78,
                            'attraction_rate' => '45.2%',
                            'rank' => 58
                        ]
                    ],
                    'January 2024' => [ // Matching parent column
                        'interview' => [
                            'number_of_interviews' => 45,
                            'number_of_deals' => 12,
                            'contract_rate' => '30%',
                            'estimated_amount' => 25892,
                            'order_amount' => 69589
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 2,
                            'number_of_appointments' => 7,
                            'attraction_rate' => '8%',
                            'rank' => 45
                        ]
                    ],
                    'Febuary 2024' => [ // Matching parent column
                        'interview' => [
                            'number_of_interviews' => 36,
                            'number_of_deals' => 52,
                            'contract_rate' => '78%',
                            'estimated_amount' => 25896,
                            'order_amount' => 12058
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 9,
                            'number_of_appointments' => 8,
                            'attraction_rate' => '2.36%',
                            'rank' => 4
                        ]
                    ],
                    '2024 Total' => [ // Matching parent column
                        'interview' => [
                            'number_of_interviews' => 789,
                            'number_of_deals' => 458,
                            'contract_rate' => '99%',
                            'estimated_amount' => 783000,
                            'order_amount' => 952000
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 1,
                            'number_of_appointments' => 1,
                            'attraction_rate' => '7.6%',
                            'rank' => 4
                        ]
                    ],
                ],
                [
                    'Base' => 'Yokohama',
                    'MC' => 'Yoji Kondo',
                    'December 2023' => [ // Matching parent column
                        'interview' => [
                            'number_of_interviews' => 99,
                            'number_of_deals' => 95,
                            'contract_rate' => '58%',
                            'estimated_amount' => 9684,
                            'order_amount' => 9589
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 9,
                            'number_of_appointments' => 9,
                            'attraction_rate' => '3%',
                            'rank' => 99
                        ]
                    ],
                    '2023 Total' => [
                        'interview' => [
                            'number_of_interviews' => 95,
                            'number_of_deals' => 97,
                            'contract_rate' => '59%',
                            'estimated_amount' => 97000,
                            'order_amount' => 95870
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 969,
                            'number_of_appointments' => 98,
                            'attraction_rate' => '45.2%',
                            'rank' => 98
                        ]
                    ],
                    'January 2024' => [ // Matching parent column
                        'interview' => [
                            'number_of_interviews' => 95,
                            'number_of_deals' => 92,
                            'contract_rate' => '30%',
                            'estimated_amount' => 95892,
                            'order_amount' => 99589
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 9,
                            'number_of_appointments' => 9,
                            'attraction_rate' => '8%',
                            'rank' => 95
                        ]
                    ],
                    'Febuary 2024' => [ // Matching parent column
                        'interview' => [
                            'number_of_interviews' => 96,
                            'number_of_deals' => 92,
                            'contract_rate' => '78%',
                            'estimated_amount' => 95896,
                            'order_amount' => 92058
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 9,
                            'number_of_appointments' => 9,
                            'attraction_rate' => '2.36%',
                            'rank' => 9
                        ]
                    ],
                    '2024 Total' => [ // Matching parent column
                        'interview' => [
                            'number_of_interviews' => 989,
                            'number_of_deals' => 958,
                            'contract_rate' => '99%',
                            'estimated_amount' => 983000,
                            'order_amount' => 952000
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 9,
                            'number_of_appointments' => 9,
                            'attraction_rate' => '7.6%',
                            'rank' => 9
                        ]
                    ],
                ],
                [
                    'Base' => 'Yokohama',
                    'MC' => 'Suzuki climb',
                    'December 2023' => [ // Matching parent column
                        'interview' => [
                            'number_of_interviews' => 457,
                            'number_of_deals' => 45,
                            'contract_rate' => '47%',
                            'estimated_amount' => 69000,
                            'order_amount' => 58000
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 9,
                            'number_of_appointments' => 9,
                            'attraction_rate' => '3%',
                            'rank' => 99
                        ]
                    ],
                    '2023 Total' => [
                        'interview' => [
                            'number_of_interviews' => 457,
                            'number_of_deals' => 45,
                            'contract_rate' => '47%',
                            'estimated_amount' => 69000,
                            'order_amount' => 58000
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 969,
                            'number_of_appointments' => 98,
                            'attraction_rate' => '45.2%',
                            'rank' => 98
                        ]
                    ],
                    'January 2024' => [ // Matching parent column
                        'interview' => [
                            'number_of_interviews' => 457,
                            'number_of_deals' => 45,
                            'contract_rate' => '47%',
                            'estimated_amount' => 69000,
                            'order_amount' => 58000
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 9,
                            'number_of_appointments' => 9,
                            'attraction_rate' => '8%',
                            'rank' => 95
                        ]
                    ],
                    'Febuary 2024' => [ // Matching parent column
                        'interview' => [
                            'number_of_interviews' => 457,
                            'number_of_deals' => 45,
                            'contract_rate' => '47%',
                            'estimated_amount' => 69000,
                            'order_amount' => 58000
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 9,
                            'number_of_appointments' => 9,
                            'attraction_rate' => '2.36%',
                            'rank' => 9
                        ]
                    ],
                    '2024 Total' => [ // Matching parent column
                        'interview' => [
                            'number_of_interviews' => 4579,
                            'number_of_deals' => 45,
                            'contract_rate' => '47%',
                            'estimated_amount' => 69000,
                            'order_amount' => 58000
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 9,
                            'number_of_appointments' => 9,
                            'attraction_rate' => '7.6%',
                            'rank' => 9
                        ]
                    ],
                ],
            ];

            $others_data = [
                [
                    'others' => 'Others',
                    'December 2023' => [ // Matching parent column
                        'interview' => [
                            'number_of_interviews' => 0,
                            'number_of_deals' => 0,
                            'contract_rate' => '0%',
                            'estimated_amount' => 0,
                            'order_amount' => 0
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 0,
                            'number_of_appointments' => 0,
                            'attraction_rate' => '0%',
                            'rank' => 1
                        ]
                    ],
                    '2023 Total' => [
                        'interview' => [
                            'number_of_interviews' => 12,
                            'number_of_deals' => 10,
                            'contract_rate' => '56%',
                            'estimated_amount' => 25000,
                            'order_amount' => 22210
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 297,
                            'number_of_appointments' => 91,
                            'attraction_rate' => '30.6%',
                            'rank' => 36
                        ]
                    ],
                    'January 2024' => [ // Matching parent column
                        'interview' => [
                            'number_of_interviews' => 5,
                            'number_of_deals' => 3,
                            'contract_rate' => '25%',
                            'estimated_amount' => 25892,
                            'order_amount' => 22589
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 0,
                            'number_of_appointments' => 0,
                            'attraction_rate' => '0%',
                            'rank' => 1
                        ]
                    ],
                    'Febuary 2024' => [ // Matching parent column
                        'interview' => [
                            'number_of_interviews' => 45,
                            'number_of_deals' => 15,
                            'contract_rate' => '58%',
                            'estimated_amount' => 25479,
                            'order_amount' => 23000
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 8,
                            'number_of_appointments' => 7,
                            'attraction_rate' => '2.58%',
                            'rank' => 4
                        ]
                    ],
                    '2024 Total' => [ // Matching parent column
                        'interview' => [
                            'number_of_interviews' => 270,
                            'number_of_deals' => 159,
                            'contract_rate' => '78%',
                            'estimated_amount' => 263000,
                            'order_amount' => 199000
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 5,
                            'number_of_appointments' => 7,
                            'attraction_rate' => '5.6%',
                            'rank' => 4
                        ]
                    ],
                ]
            ];

            $areas_data = [
                [
                    'area_total' => 'Ginza Total',
                    'December 2023' => [ // Matching parent column
                        'interview' => [
                            'number_of_interviews' => 0,
                            'number_of_deals' => 0,
                            'contract_rate' => '0%',
                            'estimated_amount' => 0,
                            'order_amount' => 0
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 0,
                            'number_of_appointments' => 0,
                            'attraction_rate' => '0%',
                            'rank' => 1
                        ]
                    ],
                    '2023 Total' => [
                        'interview' => [
                            'number_of_interviews' => 12,
                            'number_of_deals' => 10,
                            'contract_rate' => '56%',
                            'estimated_amount' => 25000,
                            'order_amount' => 22210
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 297,
                            'number_of_appointments' => 91,
                            'attraction_rate' => '30.6%',
                            'rank' => 36
                        ]
                    ],
                    'January 2024' => [ // Matching parent column
                        'interview' => [
                            'number_of_interviews' => 5,
                            'number_of_deals' => 3,
                            'contract_rate' => '25%',
                            'estimated_amount' => 25892,
                            'order_amount' => 22589
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 0,
                            'number_of_appointments' => 0,
                            'attraction_rate' => '0%',
                            'rank' => 1
                        ]
                    ],
                    'Febuary 2024' => [ // Matching parent column
                        'interview' => [
                            'number_of_interviews' => 45,
                            'number_of_deals' => 15,
                            'contract_rate' => '58%',
                            'estimated_amount' => 25479,
                            'order_amount' => 23000
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 8,
                            'number_of_appointments' => 7,
                            'attraction_rate' => '2.58%',
                            'rank' => 4
                        ]
                    ],
                    '2024 Total' => [ // Matching parent column
                        'interview' => [
                            'number_of_interviews' => 270,
                            'number_of_deals' => 159,
                            'contract_rate' => '78%',
                            'estimated_amount' => 263000,
                            'order_amount' => 199000
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 5,
                            'number_of_appointments' => 7,
                            'attraction_rate' => '5.6%',
                            'rank' => 4
                        ]
                    ],
                ],
                [
                    'area_total' => 'Yokohama Total',
                    'December 2023' => [ // Matching parent column
                        'interview' => [
                            'number_of_interviews' => 89,
                            'number_of_deals' => 45,
                            'contract_rate' => '58%',
                            'estimated_amount' => 2684,
                            'order_amount' => 2589
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 4,
                            'number_of_appointments' => 1,
                            'attraction_rate' => '3%',
                            'rank' => 89
                        ]
                    ],
                    '2023 Total' => [
                        'interview' => [
                            'number_of_interviews' => 45,
                            'number_of_deals' => 47,
                            'contract_rate' => '59%',
                            'estimated_amount' => 27000,
                            'order_amount' => 45870
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 369,
                            'number_of_appointments' => 78,
                            'attraction_rate' => '45.2%',
                            'rank' => 58
                        ]
                    ],
                    'January 2024' => [ // Matching parent column
                        'interview' => [
                            'number_of_interviews' => 45,
                            'number_of_deals' => 12,
                            'contract_rate' => '30%',
                            'estimated_amount' => 25892,
                            'order_amount' => 69589
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 2,
                            'number_of_appointments' => 7,
                            'attraction_rate' => '8%',
                            'rank' => 45
                        ]
                    ],
                    'Febuary 2024' => [ // Matching parent column
                        'interview' => [
                            'number_of_interviews' => 36,
                            'number_of_deals' => 52,
                            'contract_rate' => '78%',
                            'estimated_amount' => 25896,
                            'order_amount' => 12058
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 9,
                            'number_of_appointments' => 8,
                            'attraction_rate' => '2.36%',
                            'rank' => 4
                        ]
                    ],
                    '2024 Total' => [ // Matching parent column
                        'interview' => [
                            'number_of_interviews' => 789,
                            'number_of_deals' => 458,
                            'contract_rate' => '99%',
                            'estimated_amount' => 783000,
                            'order_amount' => 952000
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 1,
                            'number_of_appointments' => 1,
                            'attraction_rate' => '7.6%',
                            'rank' => 4
                        ]
                    ],
                ]
            ];

            $grand_data = [
                [
                    'grand_total' => 'Grand Total',
                    'December 2023' => [ // Matching parent column
                        'interview' => [
                            'number_of_interviews' => 0,
                            'number_of_deals' => 0,
                            'contract_rate' => '0%',
                            'estimated_amount' => 0,
                            'order_amount' => 0
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 0,
                            'number_of_appointments' => 0,
                            'attraction_rate' => '0%',
                            'rank' => 1
                        ]
                    ],
                    '2023 Total' => [
                        'interview' => [
                            'number_of_interviews' => 12,
                            'number_of_deals' => 10,
                            'contract_rate' => '56%',
                            'estimated_amount' => 25000,
                            'order_amount' => 22210
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 297,
                            'number_of_appointments' => 91,
                            'attraction_rate' => '30.6%',
                            'rank' => 36
                        ]
                    ],
                    'January 2024' => [ // Matching parent column
                        'interview' => [
                            'number_of_interviews' => 5,
                            'number_of_deals' => 3,
                            'contract_rate' => '25%',
                            'estimated_amount' => 25892,
                            'order_amount' => 22589
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 0,
                            'number_of_appointments' => 0,
                            'attraction_rate' => '0%',
                            'rank' => 1
                        ]
                    ],
                    'Febuary 2024' => [ // Matching parent column
                        'interview' => [
                            'number_of_interviews' => 45,
                            'number_of_deals' => 15,
                            'contract_rate' => '58%',
                            'estimated_amount' => 25479,
                            'order_amount' => 23000
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 8,
                            'number_of_appointments' => 7,
                            'attraction_rate' => '2.58%',
                            'rank' => 4
                        ]
                    ],
                    '2024 Total' => [ // Matching parent column
                        'interview' => [
                            'number_of_interviews' => 270,
                            'number_of_deals' => 159,
                            'contract_rate' => '78%',
                            'estimated_amount' => 263000,
                            'order_amount' => 199000
                        ],
                        'power_reception' => [
                            'number_of_power_received' => 5,
                            'number_of_appointments' => 7,
                            'attraction_rate' => '5.6%',
                            'rank' => 4
                        ]
                    ],
                ]
            ];

            $data = [
                'headers' => $headers,
                'mc_data' => $mc_data,
                'others_data' => $others_data,
                'areas_data' => $areas_data,
                'grand_data' => $grand_data,
            ];

            // $data = [];
	        if (count($data)) {
	            return $this->sendResponse($data, __('record_found'));
	        }

	        return $this->sendResponse([], __('record_not_found'));
	    } 
	    catch (Exception $e) {
	        return $this->sendError(__('something_went_wrong'), [$e->getMessage()], 400);
	    }
    }
}
