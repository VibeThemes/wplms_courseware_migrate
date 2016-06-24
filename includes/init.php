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
			add_action( 'admin_notices',array($this,'migration_notice' ));
			add_action('wp_ajax_migration_wp_cw_courses',array($this,'migration_wp_cw_courses'));
			add_action('wp_ajax_migration_wp_cw_course_to_wplms',array($this,'migration_wp_cw_course_to_wplms'));
		}
    }

    function migration_notice() {
    	$this->migration_status = get_option('wplms_wp_courseware_migration');
    	if(empty($this->migration_status)){
		    ?>
		    <div id="migration_wp_cw_courses" class="error notice ">
		        <p id="ccw_message"><?php printf( __('Migrate WP Courseware coruses to WPLMS %s Begin Migration Now %s', 'wplms-cwm' ),'<a id="begin_wplms_wpcw_migration" class="button primary">','</a>'); ?></p>
		        <?php wp_nonce_field('security','security'); ?>
		        <style>.wplms_ccw_progress .bar{-webkit-transition: width 0.5s ease-in-out;
    -moz-transition: width 1s ease-in-out;-o-transition: width 1s ease-in-out;transition: width 1s ease-in-out;}</style>
		        <script>
		        	jQuery(document).ready(function($){
		        		$('#begin_wplms_wpcw_migration').on('click',function(){
			        		$.ajax({
			                    type: "POST",
			                    dataType: 'json',
			                    url: ajaxurl,
			                    data: { action: 'migration_wp_cw_courses', 
			                              security: $('#security').val(),
			                            },
			                    cache: false,
			                    success: function (json) {

			                    	$('#migration_wp_cw_courses').append('<div class="wplms_ccw_progress" style="width:100%;margin-bottom:20px;height:10px;background:#fafafa;border-radius:10px;overflow:hidden;"><div class="bar" style="padding:0 1px;background:#37cc0f;height:100%;width:0;"></div></div>');

			                    	var x = 0;
			                    	var width = 100*1/json.length;
			                    	var number = width;
									var loopArray = function(arr) {
									    wpcw_ajaxcall(arr[x],function(){
									        x++;
									        if(x < arr.length) {
									         	loopArray(arr);   
									        }
									        else if (x == arr.length) {
									        	$('#migration_wp_cw_courses').removeClass('error');
									        	$('#migration_wp_cw_courses').addClass('updated');
									        }
									    }); 
									}
									
									// start 'loop'
									loopArray(json);

									function wpcw_ajaxcall(obj,callback) {
										
				                    	$.ajax({
				                    		type: "POST",
						                    dataType: 'json',
						                    url: ajaxurl,
						                    data: {
						                    	action:'migration_wp_cw_course_to_wplms', 
						                        security: $('#security').val(),
						                        id:obj.id,
						                    },
						                    cache: false,
						                    success: function (html) {
						                    	number = number + width;
						                    	$('.wplms_ccw_progress .bar').css('width',number+'%');
						                    	if(number >= 100){
										        	$('#ccw_message').html('<strong>'+x+' '+'<?php _e('Courses successfully migrated from WP Courseware to WPLMS','wplms-cwm'); ?>'+'</strong>');
										        }
						                    }
				                    	});
									    // do callback when ready
									    callback();
									}
			                    }
			                });
		        		});
		        	});
		        </script>
		    </div>
		    <?php
		}
	}


	function migration_wp_cw_courses(){
		if ( !isset($_POST['security']) || !wp_verify_nonce($_POST['security'],'security') || !is_user_logged_in()){
         	_e('Security check Failed. Contact Administrator.','vibe');
         	die();
      	}

      	global $wpdb;
		$courses = $wpdb->get_results("SELECT course_id,course_title FROM {$wpdb->prefix}wpcw_courses");
		$json=array();
		foreach($courses as $course){
			$json[]=array('id'=>$course->course_id,'title'=>$course->course_title);
		}
		update_option('wplms_wp_courseware_migration',1);
		//Migrate all the units
		$this->migrate_units();

		print_r(json_encode($json));
		die();
	}

	function migration_wp_cw_course_to_wplms(){
		if ( !isset($_POST['security']) || !wp_verify_nonce($_POST['security'],'security') || !is_user_logged_in()){
         	_e('Security check Failed. Contact Administrator.','vibe');
         	die();
      	}
      	$this->migrate_course($_POST['id']);
	}

	function migrate_units(){
		global $wpdb;
		$wpdb->query("UPDATE {$wpdb->posts} SET post_type = 'unit' WHERE post_type = 'course_unit'");
	}

	function migrate_course($course_id){
		global $wpdb;
		$courses = $wpdb->get_results("SELECT course_id,course_title,course_desc,course_opt_completion_wall,course_opt_use_certificate, course_opt_user_access FROM {$wpdb->prefix}wpcw_courses WHERE course_id = $course_id");
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
					update_post_meta($course_id,'vibe_course_free','S');
					update_post_meta($course_id,'vibe_duration','9999');
					$this->migrate_course_settings($course_id,$course->course_id,$course);	
					$this->build_curriculum($course_id,$course->course_id);
					$this->set_user_progress($course_id,$course->course_id);
				}
			}		
		}
	}

	function set_user_progress($course_id,$id){
		global $wpdb;
		$progress = $wpdb->get_results("SELECT user_id,course_progress,course_final_grade_sent FROM {$wpdb->prefix}wpcw_user_courses WHERE course_id = $id");
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

	function build_curriculum($course_id,$id){

		$curriculum = array();
		global $wpdb;
		$q = "SELECT module_id,module_title,module_order FROM {$wpdb->prefix}wpcw_modules WHERE parent_course_id = $id ORDER BY module_order ASC";
		$sections = $wpdb->get_results($q);
		if(!empty($sections)){
			foreach($sections as $section){
				if(!empty($section->module_title)){
					$curriculum[]=$section->module_title;
					$module_elements = $this->module_elements($section->module_id,$id,$course_id);
					if(!empty($module_elements)){
						foreach($module_elements as $element){
							$curriculum[]=$element;
						}	
					}
				}
			}
		}
		update_post_meta($course_id,'vibe_course_curriculum',$curriculum);
	}

	function module_elements($module_id,$cw_course_id,$course_id){
		global $wpdb;
		$unitids = $wpdb->get_results("SELECT unit_id FROM {$wpdb->prefix}wpcw_units_meta WHERE parent_module_id = $module_id AND parent_course_id = $cw_course_id ORDER BY unit_order ASC");
		$unit_ids = array();
		if(!empty($unitids)){
			foreach($unitids as $unit_id){
				$unit_ids[]=$unit_id->unit_id;
				$quizzes = $this->migrate_quizzes($unit_id->unit_id,$cw_course_id,$course_id);
				if(!empty($quizzes)){
					foreach($quizzes as $quiz){
						$unit_ids[] = $quiz;
					}
				}
			}
		}
		return $unit_ids;
	}
	function migrate_quizzes($unit_id,$cw_course_id,$course_id){
		global $wpdb;
		$quizzes = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}wpcw_quizzes WHERE parent_unit_id = $unit_id AND parent_course_id = $cw_course_id");

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

				update_post_meta($quiz_id,'vibe_quiz_course',$course_id);
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

	            if($question->question_type == 'multi'){
	            	$question->question_type = 'single';
	            	if(!empty($question->question_data_answers)){
		            	$question_data_answers = unserialize($question->question_data_answers);
	            		$options = array();
	            		if(!empty($question_data_answers)){
	            			foreach($question_data_answers as $answer){
	            				$options[] = base64_decode($answer['answer']);
	            			}
							update_post_meta($question_id,'vibe_question_options',$options);
	            		}	
					}
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