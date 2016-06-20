<?php
/**
 * Initialization functions for WP COURSEWARE MIGRATION
 * @author      VibeThemes
 * @category    Admin
 * @package     Initialization
 * @version     1.0
 */


if ( ! defined( 'ABSPATH' ) ) exit;

class WPLMS_WPCOURSEWARE_INIT{

    public static $instance;
    
    public static function init(){

        if ( is_null( self::$instance ) )
            self::$instance = new WPLMS_WPCOURSEWARE_INIT();

        return self::$instance;
    }

    private function __construct(){
    	if ( in_array( 'wp-courseware/wp-courseware.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) || (function_exists('is_plugin_active') && is_plugin_active( 'wp-courseware/wp-courseware.php'))) {  
			
			$this->migration_status = get_option('wplms_wp_courseware_migration');
			add_action( 'admin_notices',array($this,'migration_notice' ));
			add_action('admin_init',array($this,'begin_migration'));
		}
    }

    function migration_notice() {
    	
    	if(empty($this->migration_status)){
		    ?>
		    <div class="error notice">
		        <p><?php printf( __('Migrate WP Courseware coruses to WPLMS %s Begin Migration Now %s', 'my_plugin_textdomain' ),'<a href="?wplms_wp_courseware_migration=1" class="button primary">','</a>'); ?></p>
		    </div>
		    <?php
		}
	}

	function begin_migration(){
		if(!empty($_GET['wplms_wp_courseware_migration']) && empty($this->migration_status)){
			update_option('wplms_wp_courseware_migration',1);
			$this->migrate_units();
			$this->migrate_course();
		}
	}

	function migrate_units(){
		global $wpdb;
		$wpdb->query("UPDATE {$wpdb->posts} SET post_type = 'unit' WHERE post_type = 'course_unit'");
	}

	function migrate_course(){
		global $wpdb;
		$courses = $wpdb->get_results("SELECT course_id,course_title,course_desc,course_opt_completion_wall,course_opt_use_certificate, course_opt_user_access FROM {$wpdb->prefix}wpcw_courses");
		if(!empty($courses)){
			foreach($courses as $course){
				$args = array(
					'post_type'=>'course',
					'post_status'=>'publish',
					'post_title'=> $course->course_title,
					'post_content'=>$course->course_desc
				);
				$course_id = wp_insert_post($args);
				if(!empty($course_id) && !is_wp_error($course_id)){
					$this->migrate_course_settings($course_id,$id,$course);	
					$this->build_curriculum($course_id);
					$this->set_user_progress($course_id);
				}
				
			}
		}
	}

	function set_user_progress($course_id){
		global $wpdb;
		$progress = $wpdb->get_results("SELECT user_id,course_progress,course_final_grade_sent FROM {$wpdb->prefix}wpcw_user_courses WHERE course_id = $course_id");
		if(!empty($progress)){
			foreach($progress as $prg){
				bp_course_add_user_to_course($user_id,$course_id);
				if($prg->course_progress == 100){
					bp_course_update_user_course_status($user_id,$course_id,4);
					update_post_meta($course_id,$user_id,100);
				}
				update_user_meta($prg->user_id,'progress'.$course_id,$prg->course_progress);
			}
		}

		$unit_completion = $wpdb->get_results("SELECT user_id,unit_id,unit_completed_date FROM {$wpdb->prefix}wpcw_user_progress");
		if(!empty($unit_completion)){
			foreach($unit_completion as $uc){
				update_user_meta($uc->user_id,$uc->unit_id,strtotime($uc->unit_completed_date));
			}
		}

		$quiz_completion =  $wpdb->get_results("SELECT user_id,quiz_id,quiz_completed_date,quiz_correct_questions,quiz_question_total FROM {$wpdb->prefix}wpcw_user_progress_quizzes");
		if(!empty($quiz_completion)){
			foreach($quiz_completion as $qc){
				update_post_meta($qc->quiz_id,$qc->user_id,strtotime($qc->quiz_completed_date));
				update_user_meta($qc->user_id,$qc->quiz_id,$qc->quiz_correct_questions);
			}
		}
	}

	function migrate_course_settings($course_id,$id,$settings){
		if($settings->course_opt_completion_wall != 'all_visible'){
			update_post_meta($course_id,'vibe_course_progress','S');	
		}else{
			update_post_meta($course_id,'vibe_course_progress','H');	
		}
		if($settings->course_opt_user_access != 'default_show'){
			update_post_meta($course_id,'vibe_course_prev_unit_quiz_lock','S');	
		}else{
			update_post_meta($course_id,'vibe_course_prev_unit_quiz_lock','H');	
		}
		if($settings->course_opt_use_certificate != 'no_certs'){
			update_post_meta($course_id,'vibe_course_certificate','S');	
		}else{
			update_post_meta($course_id,'vibe_course_certificate','H');
		}
	}

	function build_curriculum($course_id){

		$curriculum = array();
		global $wpdb;
		$sections = $wpdb->get_results("SELECT module_id,module_title,module_order FROM {$wpdb->prefix}wpcw_modules WHERE parent_course_id = $course_id ORDER BY module_order ASC");

		if(!empty($sections)){
			foreach($sections as $section){
				$curriculum[]=$section->module_title;
				$module_elements = $this->module_elements($section->module_id,$course_id);
				if(!empty($module_elements)){
					foreach($module_elements as $element){
						$curriculum[]=$element;
					}	
				}
			}
		}
		update_post_meta($course_id,'vibe_course_curriculum',$curriculum);
	}

	function module_elements($module_id,$course_id){
		global $wpdb;
		$unitids = $wpdb->get_results("SELECT unit_id FROM {$wpdb->prefix}wpcw_units_meta WHERE parent_module_id = $module_id AND parent_course_id = $course_id ORDER BY unit_order ASC");
		$unit_ids = array();
		if(!empty($unitids)){
			foreach($unitids as $unit_id){

				$unit_ids[]=$unit_id->unit_id;
				$quizzes = $this->migrate_quizzes($unit_id->unit_id);
				if(!empty($quizzes)){
					foreach($quizzes as $quiz){
						$unit_ids = $quiz->id;
					}
				}
			}
		}
		return $unit_ids;
	}
	function migrate_quizzes($unit_id){
		global $wpdb;
		$quizzes = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}wpcw_quizzes WHERE parent_unit_id = $unit_id");
		$return_quizzes = array();
		if(!empty($quizzes)){
			foreach($quizzes as $quiz){
				$args = array(
					'post_type'=>'quiz',
					'post_status'=>'publish',
					'post_title'=>$quiz->quiz_title,
					'post_content'=>$quiz->quiz_desc
				);
				$quiz_id = wp_insert_post($args);
				$return_quizzes[]=$quiz_id;
				if($quiz->quiz_attempts_allowed<=0)
					$quiz->quiz_attempts_allowed = 0;

				update_post_meta($quiz_id,'vibe_quiz_course',$quiz->parent_course_id);
				update_post_meta($quiz_id,'vibe_duration',$quiz->quiz_timer_mode_limit);
				update_post_meta($quiz_id,'vibe_quiz_auto_evaluate','S');
				update_post_meta($quiz_id,'vibe_quiz_retakes',$quiz->quiz_attempts_allowed);
				update_post_meta($quiz_id,'vibe_quiz_passing_score',$quiz->quiz_pass_mark);
				$this->migrate_questions($quiz_id,$quiz->quiz_id);
			}
		}
		return $return_quizzes;
	}

	function migrate_questions($quiz_id,$id){
		global $wpdb;
		$questions = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}wpcw_quizzes_questions as ques LEFT JOIN {$wpdb->prefix}wpcw_quizzes_questions_map as m ON ques.question_id = m.question_id WHERE m.parent_quiz_id = $id ORDER BY m.question_order");

		$quiz_questions = array('ques'=>array(),'marks'=>array());
		if(!empty($questions)){
			foreach($questions as $question){
				$args = array(
					'post_type'=>'question',
					'post_status'=>'publish',
					'post_title'=>$question->question_type.'_'.$question->question_id,
					'post_content'=>$question->question_question
				);
				$question_id = wp_insert_post($args);
				$quiz_questions['ques'][]=$question_id;
				$quiz_questions['marks'][]=1;
				update_post_meta($question_id,'vibe_question_options',$question->question_data_answers);

	            if($question->question_type == 'multi'){
	            	$question->question_type = 'single';
	            }

	            if($question->question_type == 'open'){
	            	if($question->question_answer_type == 'single_line'){
	            		$question->question_type = 'smalltext';
	            	}else{
	            		$question->question_type = 'largetext';
	            	}
	            }
	            if($question->question_correct_answer == 'true'){
	            	$question->question_correct_answer = '1';
	            }
	            if($question->question_correct_answer == 'false'){
	            	$question->question_correct_answer = '0';
	            }
				update_post_meta($question_id,'vibe_question_type',$question->question_type);
				update_post_meta($question_id,'vibe_question_answer',$question->question_correct_answer);
				update_post_meta($question_id,'vibe_question_hint',$question->question_answer_hint);
				update_post_meta($question_id,'vibe_question_explaination',$question->question_answer_explanation);
			}

			update_post_meta($quiz_id,'vibe_quiz_questions',$quiz_questions);
		}
	}
}

WPLMS_WPCOURSEWARE_INIT::init();