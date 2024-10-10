<?php

namespace App\Http\Controllers;

use UserType;
use Carbon\Carbon;
use App\Models\Question;
use MongoDB\Driver\Session;
use Illuminate\Http\Request;
use App\Utils\Helpers\Status;
use App\Models\AttemptedSkill;
use App\Models\AttemptedEvaluation;
use App\Models\AttemptedQuestion;
use App\Models\GuestAttemptedSkill;
use function GuzzleHttp\Promise\all;
use App\Utils\Helpers\QuestionsType;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Cookie;
use phpDocumentor\Reflection\Types\Null_;
use App\Services\QuestionServiceInterface;
use App\Utils\Helpers\SmartScoreVariations;
use Illuminate\Contracts\Encryption\DecryptException;

class AttemptedQuestionController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    protected $QuestionService;

    public function __construct(QuestionServiceInterface $questionService)
    {
        $this->QuestionService = $questionService;
    }

    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $all_fields = $request->except('_token');
        $result = null;

        if ($request->has(['question_id', 'attempted_skill_id'])) {
            try {
                $question_id = Crypt::decrypt($all_fields['question_id']);
                $attempted_skill_id = Crypt::decrypt($all_fields['attempted_skill_id']);
            } catch (DecryptException $e) {
                return back()->with('error','Invalid data, retry again');
            }
        } 
        else {
            return back()->with('error','Invalid data, retry again');
        }

        $question = $this->QuestionService->getQuestionById($question_id);

        $user_answers = null;
        $attemptedAnswerData = null;
        $attemptedSkillData = null;

        if ($request->has('answers')) {
            $user_answers = $all_fields['answers'];
            $grade_id = $question->grade_id;
            $subject_id = $question->subject_id;
            $chapter_id = $question->chapter_id;
            $skill_id = $question->skill_id;
            // $attempted_skill_id = session('attempted_skill_data')['id'];

            // INDIVIDUAL QUESTION AND SKILL TIME CALCULATION - START
            $start_time = $all_fields['start_time'];
            $time_spent = $all_fields['time_spent'];

            if($start_time) {
                $start  = new Carbon($start_time);
                $end    = new Carbon($time_spent);
                
                $time_spent_on_question = $start->diff($end)->format('%H:%I:%S');
            }
            else {
                $time_spent_on_question = $time_spent;
            }
            // END
            
            $attempted_answers = null;
            $answer_title = null;

            // Start if fill in the blanks and dropdown question types
            if ($question->question_type == QuestionsType::FillInTheBlanks || $question->question_type ==  QuestionsType::Dropdown) {
                
                $attempted_answers = implode(",",$user_answers);
                $correct_answer = $question->answers[0]['title'];
                $result = $attempted_answers == $correct_answer;
                $answer_title = json_encode($attempted_answers);
                $attempted_answers = json_encode($question->answers[0]['id']);
            }
            // End fill in the blanks and dropdown question types

            // Start if mcqs,multiple mcqs and multiple group mcqs question types
            elseif ($question->question_type == QuestionsType::MCQs || $question->question_type == QuestionsType::MultipleMCQs ||  $question->question_type == QuestionsType::MultipleGroupMCQs) {
                
                $attempted_answers = $user_answers;
                // $correct_answer = json_decode($question->correct_answers);
                $correct_answer = $question->answers->where('is_correct',true)->pluck('id')->toarray();
                
                if(count($correct_answer) == count($attempted_answers)) {
                    $result = array_diff($correct_answer, $attempted_answers);
                }
                elseif(count($correct_answer) > count($attempted_answers)) {
                    $result = array_diff($correct_answer, $attempted_answers);
                }
                elseif(count($attempted_answers ) > count($correct_answer)) {
                    $result = array_diff($attempted_answers, $correct_answer);
                }
                else {
                    $result = array_diff($attempted_answers, $correct_answer);
                }

                $result = empty($result) ? true : false;
                $attempted_answers = json_encode($attempted_answers);
            }
            // End mcqs,multiple mcqs and multiple group mcqs question types

            // Start if rearranged question type
            elseif ($question->question_type == QuestionsType::Rearranged) {
                $result = true;

                foreach (json_decode($question->answers[0]->title) as $key => $item) {
                    $result = $item == $user_answers[$key];

                    if($item != $user_answers[$key]){
                        $result = false;

                        break;
                    }
                }

                $attempted_answers = $question->answers[0]->id;
                $answer_title = json_encode($user_answers);

            }
            //End rearranged question type

            elseif ($question->question_type == QuestionsType::DragAndDrop) {
                return back()->with('incorrect','Attempt Logic is pending for drag and drop type');
            }

            // Set userable id and type if child is login, using this further in attempted questions and attempted skill table
            if( Auth::guard('parent')->check() && session('active_user_type') == UserType::Child) {
                $userable_id = session()->get('active_user')['id'];
                $userable_type = UserType::Child;

                // Save attempted answer in database
                $attempeted_answer_data = [
                    'is_correct'        =>  $result ?? null,
                    'userable_id'       =>  $userable_id ?? null,
                    'userable_type'     =>  $userable_type ?? null,
                    'question_id'       =>  $question_id ?? null,
                    'answer_id'         =>  $attempted_answers ?? null,
                    'answer_title'      =>  $answer_title ?? null,
                    'grade_id'          =>  $grade_id ?? null,
                    'subject_id'        =>  $subject_id ?? null,
                    'chapter_id'        =>  $chapter_id ?? null,
                    'skill_id'          =>  $skill_id ?? null,
                    'time_spent'        =>  $time_spent_on_question ?? null,
                ];
                self::attemptedQuestionData($attempeted_answer_data);

                // Update attempted skill data according to result and calculation in attemptedSkillData function
                self::attemptedSkillData($result, $time_spent, $attempted_skill_id, $userable_type, $question_id);

            }
            elseif( Auth::guard('parent')->check() && session('active_user_type') == UserType::Parent) {
                $userable_id = session()->get('active_user')['id'];
                $userable_type = UserType::Parent;
                self::attemptedSkillData($result, $time_spent, $attempted_skill_id, $userable_type, $question_id);
            }
            else {
                $userable_id = $request->ip();
                $userable_type = UserType::Guest;
                self::attemptedSkillData($result, $time_spent, $attempted_skill_id, $userable_type, $question_id);
            }
        }

        // Check result and return back to question page
        if ($result) {
            return back()->with('correct','Correct  Answer');
        }
        else {
            session()->put('attempted_answers', $user_answers);
            session()->put('question', $question);
            return back()->with(['incorrect'=>'Sorry, Incorrect Answer']);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    protected function attemptedQuestionData($data)
    {
        AttemptedQuestion::create($data);
    }

    protected function attemptedSkillData($result, $time_spent ,$attempted_skill_id, $userable_type, $question_id)
    {
        if (($userable_type == UserType::Parent) || ($userable_type == UserType::Guest)) {

            $skill_details = GuestAttemptedSkill::find($attempted_skill_id);
            if (isset($skill_details->question_ids)) {
                $skill_details->question_ids = json_encode(array_merge(json_decode($skill_details->question_ids), [$question_id]));
            } else {
                $skill_details->question_ids = json_encode(array_values([$question_id]));
            }
            $skill_details->save();
        } 
        else {
            $skill_details = AttemptedSkill::find($attempted_skill_id);
        }

        $smart_score = $skill_details->current_smart_score;
        $missed = $skill_details->question_missed;
        $status = Status::Active;

        if ($result == false) {
            $missed = $missed + 1;
            if ($smart_score < 0) {
                $smart_score = 0;
            }
        }

        if ($result == true) {
            if (($smart_score >= 0 && $smart_score < 45) || $smart_score == null) {
                $smart_score = $skill_details->current_smart_score + SmartScoreVariations::PlusIfScoreBetween0And45;
            } elseif ($smart_score >= 45 && $smart_score < 75) {
                $smart_score = $skill_details->current_smart_score + SmartScoreVariations::PlusIfScoreBetween45And75;
            } elseif ($smart_score >= 75 && $smart_score < 90) {
                $smart_score = $skill_details->current_smart_score + SmartScoreVariations::PlusIfScoreBetween75And90;
            } elseif ($smart_score >= 90 && $smart_score <= 100) {
                $smart_score = $skill_details->current_smart_score + SmartScoreVariations::PlusIfScoreBetween90And100;
                
                if ($smart_score >= 100) {
                    $smart_score = 100;
                    $status = Status::Inactive;
                }
            }
        }

        if(empty($skill_details->question_ids)) {
            $ids_array = [];
        }
        else {
            $ids_array = json_decode($skill_details->question_ids);
        }
        
        // dd($question_id);
        if(!in_array($question_id, $ids_array))
        {
            array_push($ids_array, $question_id);
            $ids_array = json_encode($ids_array);
        }

        $attempted_skill_data = [
            'question_ids' => $ids_array,
            'question_answered' => $skill_details->question_answered + 1,
            'question_missed' => $missed ?? null,
            'time_spent' => $time_spent ?? null,
            'status' => $status ?? null,
            'current_smart_score' => $smart_score ?? null,
            // 'previous_smart_score'=> 'previous_smart_score',
        ];

        if ($userable_type == UserType::Guest && $skill_details->question_answered >= 5 ) {
            session()->put('reached_daily_limit', true);
        }
        elseif ($userable_type == UserType::Guest && $skill_details->question_answered < 6 ) {
            $skill_details->update($attempted_skill_data);
        }
        else{
            $skill_details->update($attempted_skill_data);
        }

        if ($smart_score >= 100) {
            session()->put('master_skill', $attempted_skill_data);
        }
    }

    public function submitEvaluation(Request $request)
    {
        // dd($request);
        $all_fields = $request->except('_token');
        $result = null;

        if ($request->has(['question_id', 'attempted_evaluation_id'])) {
            try {
                $question_id = Crypt::decrypt($all_fields['question_id']);
                $attempted_evaluation_id = Crypt::decrypt($all_fields['attempted_evaluation_id']);
            } catch (DecryptException $e) {
                return back()->with('error','Invalid data, try again');
            }
        } 
        else {
            return back()->with('error','Invalid data, try again');
        }

        $question = $this->QuestionService->getQuestionById($question_id);

        $user_answers = null;
        $attemptedAnswerData = null;
        $attemptedSkillData = null;

        if ($request->has('answers')) {
            $user_answers = $all_fields['answers'];
            $grade_id = $question->grade_id;
            $subject_id = $question->subject_id;
            $chapter_id = $question->chapter_id;
            $skill_id = $question->skill_id;

            // INDIVIDUAL QUESTION AND SKILL, TIME CALCULATION - START
            $start_time = $all_fields['start_time'];
            $time_spent = $all_fields['time_spent'];

            if($start_time) {
                $start  = new Carbon($start_time);
                $end    = new Carbon($time_spent);
                
                $time_spent_on_question = $start->diff($end)->format('%H:%I:%S');

                // 
                // $time_spent_on_skill = $time_spent_on_question;
            }
            else {
                $time_spent_on_question = $time_spent;
            }
            // END
            
            $attempted_answers = null;
            $answer_title = null;

            // Start if fill in the blanks and dropdown question types
            if ($question->question_type == QuestionsType::FillInTheBlanks || $question->question_type ==  QuestionsType::Dropdown) {
                
                $attempted_answers = implode(",", $user_answers);
                $correct_answer = $question->answers[0]['title'];
                $result = $attempted_answers == $correct_answer;
                $answer_title = json_encode($attempted_answers);
                $attempted_answers = json_encode($question->answers[0]['id']);
            }
            // End fill in the blanks and dropdown question types

            // Start if mcqs,multiple mcqs and multiple group mcqs question types
            elseif ($question->question_type == QuestionsType::MCQs || $question->question_type == QuestionsType::MultipleMCQs ||  $question->question_type == QuestionsType::MultipleGroupMCQs) {
                
                $attempted_answers = $user_answers;
                // $correct_answer = json_decode($question->correct_answers);
                $correct_answer = $question->answers->where('is_correct', true)->pluck('id')->toArray();
                
                if(count($correct_answer) == count($attempted_answers)) {
                    $result = array_diff($correct_answer, $attempted_answers);
                }
                elseif(count($correct_answer) > count($attempted_answers)) {
                    $result = array_diff($correct_answer, $attempted_answers);
                }
                elseif(count($attempted_answers ) > count($correct_answer)) {
                    $result = array_diff($attempted_answers, $correct_answer);
                }
                else {
                    $result = array_diff($attempted_answers, $correct_answer);
                }

                $result = empty($result) ? true : false;
                $attempted_answers = json_encode($attempted_answers);
            }
            // End mcqs,multiple mcqs and multiple group mcqs question types

            // Start if rearranged question type
            elseif ($question->question_type == QuestionsType::Rearranged) {
                $result = true;

                foreach (json_decode($question->answers[0]->title) as $key => $item) {
                    $result = $item == $user_answers[$key];

                    if($item != $user_answers[$key]){
                        $result = false;

                        break;
                    }
                }

                $attempted_answers = $question->answers[0]->id;
                $answer_title = json_encode($user_answers);
            }
            //End rearranged question type

            elseif ($question->question_type == QuestionsType::DragAndDrop) {
                return back()->with('incorrect', 'Attempt Logic is pending for drag and drop type');
            }

            // Set userable id and type if child is login, using this further in attempted questions and attempted skill table
            if( Auth::guard('parent')->check() && session('active_user_type') == UserType::Child) {
                $userable_id = session()->get('active_user')['id'];
                $userable_type = UserType::Child;

                // Save attempted answer in database
                $attempeted_answer_data = [
                    'is_correct'                =>  $result ?? null,
                    'attempted_evaluation_id'   =>  $attempted_evaluation_id ?? null,
                    'userable_id'               =>  $userable_id ?? null,
                    'userable_type'             =>  $userable_type ?? null,
                    'question_id'               =>  $question_id ?? null,
                    'answer_id'                 =>  $attempted_answers ?? null,
                    'answer_title'              =>  $answer_title ?? null,
                    'grade_id'                  =>  $grade_id ?? null,
                    'subject_id'                =>  $subject_id ?? null,
                    'chapter_id'                =>  $chapter_id ?? null,
                    'skill_id'                  =>  $skill_id ?? null,
                    'time_spent'                =>  $time_spent_on_question ?? null,
                ];
                self::attemptedQuestionData($attempeted_answer_data);

                // Update attempted skill data according to result and calculation in attemptedSkillData function
                self::attemptedEvaluationData($result, 
                                                $time_spent, 
                                                $time_spent_on_question, 
                                                $attempted_evaluation_id, 
                                                $userable_type, 
                                                $question
                                            );
            }
        }

        // Check result and return back to question page
        if ($result) {
            return back()->with('correct','Correct  Answer');
        }
        else {
            session()->put('attempted_answers', $user_answers);
            session()->put('question', $question);
            return back()->with(['incorrect'=>'Sorry, Incorrect Answer']);
        }
    }

    protected function attemptedEvaluationData($result, 
                                                $time_spent, 
                                                $time_spent_on_question, 
                                                $attempted_evaluation_id, 
                                                $userable_type, 
                                                $question
                                            )
    {
        
        $parent_eva_details = AttemptedEvaluation::find($attempted_evaluation_id);

        $missed = $parent_eva_details->question_missed;
        $status = Status::Active;

        if ($result == false) {
            $missed = $missed + 1;
        }

        if(empty($parent_eva_details->question_ids)) {
            $ids_array = [];
        }
        else {
            $ids_array = json_decode($parent_eva_details->question_ids);
        }
        
        if(!in_array($question->id, $ids_array))
        {
            array_push($ids_array, $question->id);
            $ids_array = json_encode($ids_array);
        }

        $attempted_evaluation_data = [
            'question_ids' => $ids_array,
            'question_answered' => $parent_eva_details->question_answered + 1,
            'question_missed' => $missed ?? null,
            'time_spent' => $time_spent ?? null,
            'status' => $status ?? null,
        ];
        
        $parent_eva_details->update($attempted_evaluation_data);


        /*** 
         * CHILD DATA PROCESSING - START
         ***/
        $child_eva_details  = AttemptedEvaluation::where('userable_id', session()->get('active_user')['id'])
                                        ->where('userable_type', UserType::Child)
                                        ->where('self_parent_id', $parent_eva_details->id)
                                        ->where('status', Status::Active)
                                        ->where('grade_id', $question->grade_id)
                                        ->where('subject_id', $question->subject_id)
                                        ->where('chapter_id', $question->chapter_id)
                                        ->where('skill_id', $question->skill_id)
                                        ->first();

        // IF NO $child_eva_details FOUND THEN CREATE IT.
        if(!isset($child_eva_details)) {
            $child_eva_details = AttemptedEvaluation::create([
                'self_parent_id' => $parent_eva_details->id,
                'userable_id' => session()->get('active_user')['id'],
                'userable_type' => UserType::Child,
                'session_id' => session()->get('session_id'),
                'grade_id' => $question->grade_id,
                'subject_id' => $question->subject_id,
                'chapter_id' => $question->chapter_id,
                'skill_id' => $question->skill_id,
                'status' => Status::Active,
            ]);
        }

        $missed_child_row = $child_eva_details->question_missed;

        if ($result == false) {
            $missed_child_row = $missed_child_row + 1;
        }

        if(empty($child_eva_details->question_ids)) {
            $ids_array_child_row = [];
        }
        else {
            $ids_array_child_row = json_decode($child_eva_details->question_ids);
        }
        
        if(!in_array($question->id, $ids_array_child_row))
        {
            array_push($ids_array_child_row, $question->id);
            $ids_array_child_row = json_encode($ids_array_child_row);
        }

        $time_spent_child_row = $child_eva_details->time_spent;
        if(empty($child_eva_details->time_spent)) {
            $time_spent_child_row = '00:00:00';
        }

        $time_spent_child_row = strtotime($time_spent_child_row)+strtotime($time_spent_on_question);
        $time_spent_child_row = date('H:i:s', $time_spent_child_row);

        $attempted_evaluation_data_child_row = [
            'question_ids' => $ids_array_child_row,
            'question_answered' => $child_eva_details->question_answered + 1,
            'question_missed' => $missed_child_row ?? null,
            'time_spent' => $time_spent_child_row ?? null,
            'status' => Status::Active,
        ];
        
        $child_eva_details->update($attempted_evaluation_data_child_row);

        /*** 
         * CHILD DATA PROCESSING - END
         ***/

        
    }

    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
}
