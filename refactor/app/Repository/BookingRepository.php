<?php

namespace DTApi\Repository;

use Event;
use Carbon\Carbon;
use Monolog\Logger;
use Illuminate\Http\Request;
use DTApi\Http\Requests\UserJobRequest,StoreRequest,GetAllRequest;
use DTApi\Mailers\AppMailer,MailerInterface;
use DTApi\Events\JobWasCreated,JobWasCanceled,SessionEnded;
use DTApi\Models\UsersBlacklist,Translator,Language,User,Job,UserMeta,UserLanguages;
use DTApi\Helpers\DateTimeHelper,SendSMSHelper,TeHelper;
use Illuminate\Support\Facades\DB,Auth,Log;
use Monolog\Handler\StreamHandler,FirePHPHandler,;
//we dont need alot of file line to imoport single directory files
/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{

    protected $model;
    protected $mailer;
    protected $logger;

    /**
     * @param Job $model
     */
    function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->logger = new Logger('admin_logger');

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobs($user_id)
    {
        $cuser = User::findorFail($user_id);
        $usertype = '';
        $emergencyJobs = array();
        $noramlJobs = array();
        
        //We can use laravel helper function foreach mysqli data but we dont have helper so we are using simple foreach
        if ($cuser) {
		    if ($cuser->is('customer')) {
		    $jobs = $cuser->jobs()->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')->whereIn('status', ['pending', 'assigned', 'started'])->orderBy('due', 'asc')->get();
		    $usertype = 'customer';
		} elseif ($cuser->is('translator')) {
		    $jobs = Job::getTranslatorJobs($cuser->id, 'new');
		    $jobs = $jobs->pluck('jobs')->all();
		    $usertype = 'translator';
		}
		if ($jobs) {
           		 foreach ($jobs as $jobitem) {
               			 if ($jobitem->immediate == 'yes') {
                   			 $emergencyJobs[] = $jobitem;
                		} else {
                   			 $noramlJobs[] = $jobitem;
                		}	
            }
            $noramlJobs = collect($noramlJobs)->each(function ($item, $key) use ($user_id) {
                $item['usercheck'] = Job::checkParticularJob($user_id, $item);
            })->sortBy('due')->all();
        }

        return ['emergencyJobs' => $emergencyJobs, 'noramlJobs' => $noramlJobs, 'cuser' => $cuser, 'usertype' => $usertype];
        } else{
        	abort(404, 'Data not found');
        }
        
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobsHistory($user_id, UserJobRequest $request)
    {	
    	//The optimal approach to validate data is not repository we should do it in reuqests
        $cuser = User::find($user_id);
        $usertype = '';
        $emergencyJobs = array();
        $noramlJobs = array();
        if ($cuser && $cuser->is('customer')) {
            $jobs = $cuser->jobs()->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])->orderBy('due', 'desc')->paginate(15);
            $usertype = 'customer';
            return ['emergencyJobs' => $emergencyJobs, 'noramlJobs' => [], 'jobs' => $jobs, 'cuser' => $cuser, 'usertype' => $usertype, 'numpages' => 0, 'pagenum' => 0];
        } elseif ($cuser && $cuser->is('translator')) {
            $jobs_ids = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $pagenum);
            $totaljobs = $jobs_ids->total();
            $numpages = ceil($totaljobs / 15);

            $usertype = 'translator';

            $jobs = $jobs_ids;
            $noramlJobs = $jobs_ids;
//            $jobs['data'] = $noramlJobs;
//            $jobs['total'] = $totaljobs;
            return ['emergencyJobs' => $emergencyJobs, 'noramlJobs' => $noramlJobs, 'jobs' => $jobs, 'cuser' => $cuser, 'usertype' => $usertype, 'numpages' => $numpages, 'pagenum' => $pagenum];
        }
    }

    /**
     * @param $user
     * @param $data
     * @return mixed
     */
    public function store($user, StoreRequest $request)
    {
	//The optimal approach to validate data is not repository we should do it in reuqests
	
        $immediatetime = 5;
        $consumer_type = $user->userMeta->consumer_type;
        if (Auth::user()->hasRole('CUSTOMER_ROLE_ID')) {
            // close to me this validation must be in request or services directory

            $data['customer_town'] = $cuser->userMeta->city;
            $data['customer_type'] = $cuser->userMeta->customer_type;

            //Event::fire(new JobWasCreated($job, $data, '*'));

//            $this->sendNotificationToSuitableTranslators($job->id, $data, '*');// send Push for New job posting
        } else {
            $response['status'] = 'fail';
            $response['message'] = "Translator can not create booking";
        }

        return $response;

    }

    /**
     * @param $data
     * @return mixed
     */
    public function storeJobEmail(UserStoreJobEmail $request)
    {
        $user_type = $request['user_type'];
        $job = Job::findOrFail($request['user_email_job_id']);
        $job->create($request->all();

        if (!empty($job->user_email)) {
            $email = $job->user_email;
            $name = $user->name;
        } else {
            $email = $user->email;
            $name = $user->name;
        }
        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
        $send_data = [
            'user' => Auth::user(),
            'job'  => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-created', $send_data);

        $response['type'] = $user_type;
        $response['job'] = $job;
        $response['status'] = 'success';
        $data = $this->jobToData($job);
        Event::fire(new JobWasCreated($job, $data, '*'));
        return $response;

    }

    /**
     * @param $job
     * @return array
     */
    public function jobToData(JobRequest $request)
    {
	//Again we have to check this in request not in repository
        $due_Date = explode(" ", $job->due);
        $due_date = $due_Date[0];
        $due_time = $due_Date[1];

        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;

            if ($job->certified == 'both') {
                $data['job_for'][] = 'Godkänd tolk';
                $data['job_for'][] = 'Auktoriserad';
            } else if ($job->certified == 'yes') {
                $data['job_for'][] = 'Auktoriserad';
            } else if ($job->certified == 'n_health') {
                $data['job_for'][] = 'Sjukvårdstolk';
            } else if ($job->certified == 'law' || $job->certified == 'n_law') {
                $data['job_for'][] = 'Rätttstolk';
            } else {
                $data['job_for'][] = $job->certified;
            }

        return $data;

    }

    /**
     * @param array $post_data
     */
    public function jobEnd(EndJobRequest $request)
    {
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($request->job_id);
        $user = $job->user()->get()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_explode = explode(':', $job->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'faktura'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $user->name, $subject, 'emails.session-ended', $data);

        $job->save();

        $tr = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

        Event::fire(new SessionEnded($job, ($post_data['userid'] == $job->user_id) ? $tr->user_id : $job->user_id));

        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'lön'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = $completeddate;
        $tr->completed_by = $post_data['userid'];
        $tr->save();
    }

    /**
     * Function to get all Potential jobs of user with his ID
     * @param $user_id
     * @return array
     */
    public function getPotentialJobIdsWithUserId($user_id)
    {
        $user_meta = UserMeta::findorFail('user_id', $user_id);
        if($user_meta){
        $translator_type = $user_meta->translator_type;
        $job_type = 'unpaid';
        if ($translator_type == 'professional'){
            $job_type = 'paid';   /*show all jobs for professionals.*/
            }
        else if ($translator_type == 'rwstranslator'){
            $job_type = 'rws';  /* for rwstranslator only show rws jobs. */
            }
        else ($translator_type == 'volunteer'){
            $job_type = 'unpaid';  /* for volunteers only show unpaid jobs. */
	}
        $languages = UserLanguages::where('user_id', '=', $user_id)->get();
        $userlanguage = collect($languages)->pluck('lang_id')->all();
        $job_ids = Job::getJobs($user_id, $job_type, 'pending', $userlanguage, $user_meta->gender, $user_meta->translator_level;);
        foreach ($job_ids as $k => $v)     // checking translator town
        {
            $job = Job::find($v->id);
            $jobuserid = $job->user_id;
            $checktown = Job::checkTowns($jobuserid, $user_id);
            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
                unset($job_ids[$k]);
            }
        }
        $jobs = TeHelper::convertJobIdsInObjs($job_ids);
        return $jobs;
    }
    }
    else{
    		abort(404, 'User not found');
    }

    /**
     * @param $job
     * @param array $data
     * @param $exclude_user_id
     */
    public function sendNotificationTranslator($job, $data = [], $exclude_user_id)
    {
        $users = User::all();
        $translator_array = array();            // suitable translators (no need to delay push)
        $delpay_translator_array = array();     // suitable translators (need to delay push)

        foreach ($users as $oneUser) {		// we should better use laravel helper function rather then foreach
            if ($oneUser->user_type == '2' && $oneUser->status == '1' && $oneUser->id != $exclude_user_id) { // user is translator and he is not disabled
                if (!$this->isNeedToSendPush($oneUser->id)) continue;
                $not_get_emergency = TeHelper::getUsermeta($oneUser->id, 'not_get_emergency');
                if ($data['immediate'] == 'yes' && $not_get_emergency == 'yes') continue;
                $jobs = $this->getPotentialJobIdsWithUserId($oneUser->id); // get all potential jobs of this user
                foreach ($jobs as $oneJob) {
                    if ($job->id == $oneJob->id) { // one potential job is the same with current job
                        $userId = $oneUser->id;
                        $job_for_translator = Job::assignedToPaticularTranslator($userId, $oneJob->id);
                        if ($job_for_translator == 'SpecificJob') {
                            $job_checker = Job::checkParticularJob($userId, $oneJob);
                            if (($job_checker != 'userCanNotAcceptJob')) {
                                if ($this->isNeedToDelayPush($oneUser->id)) {
                                    $delpay_translator_array[] = $oneUser;
                                } else {
                                    $translator_array[] = $oneUser;
                                }
                            }
                        }
                    }
                }
            }
        }
        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';
        $msg_contents = '';
        if ($data['immediate'] == 'no') {
            $msg_contents = 'Ny bokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min ' . $data['due'];
        } else {
            $msg_contents = 'Ny akutbokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min';
        }
        $msg_text = array(
            "en" => $msg_contents
        );

        $logger = new Logger('push_logger');

        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job->id, [$translator_array, $delpay_translator_array, $msg_text, $data]);
        $this->sendPushNotificationToSpecificUsers($translator_array, $job->id, $data, $msg_text, false);       // send new booking push to suitable translators(not delay)
        $this->sendPushNotificationToSpecificUsers($delpay_translator_array, $job->id, $data, $msg_text, true); // send new booking push to suitable translators(need to delay)
    }

    /**
     * Sends SMS to translators and retuns count of translators
     * @param $job
     * @return int
     */
    public function sendSMSNotificationToTranslator($job)
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->findorFail();

        // prepare message templates
        if($jobPosterMeta){
		$date = date('d.m.Y', strtotime($job->due));
		$time = date('H:i', strtotime($job->due));
		$duration = $this->convertToHoursMins($job->duration);
		$city = $job->city ? $job->city : $jobPosterMeta->city;

		$phoneJobMessageTemplate = trans('sms.phone_job', ['date' => $date, 'time' => $time, 'duration' => $duration, 'jobId' => $job->id;]);

		$physicalJobMessageTemplate = trans('sms.physical_job', ['date' => $date, 'time' => $time, 'town' => $city, 'duration' => $duration, 'jobId' => $job->id;);

        // analyse weather it's phone or physical; if both = default to phone
        if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no') {
            // It's a physical job
            $message = $physicalJobMessageTemplate;
        } else if ($job->customer_physical_type == 'no' && $job->customer_phone_type == 'yes') {
            // It's a phone job
            $message = $phoneJobMessageTemplate;
        } else if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'yes') {
            // It's both, but should be handled as phone job
            $message = $phoneJobMessageTemplate;
        } else {
            // This shouldn't be feasible, so no handling of this edge case
            $message = '';
        }

        // send messages via sms handler
        foreach ($translators as $translator) {
            // send message to translator
            $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
            Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
        }

        return count($translators);
    }
        }
        else{
        	abort(404, 'Page not found');
        }

    /**
     * Function to delay the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToDelayPush($user_id)
    {
    	//we must user ternary operator over here with if statment
    	if(isset($user_id))
        (!DateTimeHelper::isNightTime()) ? return false : return true;
        $not_get_nighttime = TeHelper::getUsermeta($user_id, 'not_get_nighttime');
        ($not_get_nighttime == 'yes') ? return true : return false;
        }
        else{
        	abort(404, 'Page not found');
        }
    }

    /**
     * Function to check if need to send the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToSendPush($user_id)
    {
    	if(isset($user_id)){
	    	$not_get_notification = TeHelper::getUsermeta($user_id, 'not_get_notification');
		(($not_get_notification == 'yes'))? return false : return true;
		return true;
    	}else{
    		abort(404, 'User not found');
    	}
   
    }

    /**
     * Function to send Onesignal Push Notifications with User-Tags
     * @param $users
     * @param $job_id
     * @param $data
     * @param $msg_text
     * @param $is_need_delay
     */
    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {

        $logger = new Logger('push_logger');

        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);
        
        // Below 2 lines must be reduandant because same condition is going on
        $onesignalAppID = config('app.prodOnesignalAppID');
        $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.prodOnesignalApiKey'));

        $user_tags = $this->getUserTagsStringFromArray($users);
	
	($job_id) ? $data['job_id'] = $job_id : abort(404, 'Page not found'); 
        $ios_sound = 'default';
        $android_sound = 'default';

        if ($data['notification_type'] == 'suitable_job') {
            ($data['immediate'] == 'no')? 
            $android_sound = 'normal_booking'; $ios_sound = 'normal_booking.mp3'; 
            : $android_sound = 'emergency_booking'; $ios_sound = 'normal_booking.mp3';{
                
        }

        $fields = array(
            'app_id'         => $onesignalAppID,
            'tags'           => json_decode($user_tags),
            'data'           => $data,
            'title'          => array('en' => 'DigitalTolk'),
            'contents'       => $msg_text,
            'ios_badgeType'  => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound'  => $android_sound,
            'ios_sound'      => $ios_sound
        );
        if (isset($is_need_delay)) {
            $next_business_time = DateTimeHelper::getNextBusinessTimeString();
            $fields['send_after'] = $next_business_time;
        }
        else{
        	abort(404, 'User not found');
        }
        $fields = json_encode($fields);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', $onesignalRestAuthKey));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        $response = curl_exec($ch);
        $logger->addInfo('Push send for job ' . $job_id . ' curl answer', [$response]);
        curl_close($ch);
    }

    /**
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job)
    {

        $job_types = $job->pluck('job_type')->toArray();
	
	if(count($job_types)>0){
	foreach($job_types as $job_type){
        if ($job_type == 'paid')
            $translator_type = 'professional';
        else if ($job_type == 'rws')
            $translator_type = 'rwstranslator';
        else if ($job_type == 'unpaid')
            $translator_type = 'volunteer';
        }
        }

        $joblanguage = $job->pluck('from_language_id')->toArray();
        $gender = $job->pluck('gender')->toArray();
        $translator_level = [];
        if (!empty($job->certified)) {
            if ($job->certified == 'yes' || $job->certified == 'both') {
                $translator_level[] = 'Certified';
                $translator_level[] = 'Certified with specialisation in law';
                $translator_level[] = 'Certified with specialisation in health care';
            }
            else if($job->certified == 'law' || $job->certified == 'n_law')
            {
                $translator_level[] = 'Certified with specialisation in law';
            }
            elseif($job->certified == 'health' || $job->certified == 'n_health')
            {
                $translator_level[] = 'Certified with specialisation in health care';
            }
            else if ($job->certified == 'normal' || $job->certified == 'both') {
                $translator_level[] = 'Layman';
                $translator_level[] = 'Read Translation courses';
            }
            elseif ($job->certified == null) {
            // there must be not certification if certified is null
                $translator_level[] = 'Not Certified';
            }
        }

        $blacklist = UsersBlacklist::where('user_id', $job->user_id)->get();
        $translatorsId = collect($blacklist)->pluck('translator_id')->all();
        $users = User::getPotentialUsers($translator_type, $joblanguage, $gender, $translator_level, $translatorsId);= TeHelper::convertJobIdsInObjs($job_ids);
        //there must be validation
        (count($users)>0? return $users; : abort(404, 'User not found'))

    }

    /**
     * @param $id
     * @param $data
     * @return mixed
     */
    public function updateJob($id, $data, $cuser)
    {
    	if(Auth::user() && isset($id) && isset($data))
        {
        $job = Job::findorFail($id);
		if($job){
		$current_translator = $job->translatorJobRel->where('cancel_at', Null)->first();
			if (is_null($current_translator))
			    $current_translator = $job->translatorJobRel->where('completed_at', '!=', Null)->first();

			$log_data = [];

			$langChanged = false;
			
			$changeTranslator = $this->changeTranslator($current_translator, $data, $job);
				if ($changeTranslator['translatorChanged']) $log_data[] = $changeTranslator['log_data'];

				$changeDue = $this->changeDue($job->due, $data['due']);
				if ($changeDue['dateChanged']) {
				    $old_time = $job->due;
				    $job->due = $data['due'];
				    $log_data[] = $changeDue['log_data'];
				}

				if ($job->from_language_id != $data['from_language_id']) {
				    $log_data[] = [
					'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
					'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
				    ];
				    $old_lang = $job->from_language_id;
				    $job->from_language_id = $data['from_language_id'];
				    $langChanged = true;
				}

				$changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
				if ($changeStatus['statusChanged'])
				    $log_data[] = $changeStatus['log_data'];

				$job->admin_comments = $data['admin_comments'];

				$this->logger->addInfo('USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has been updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data:  ', $log_data);

				$job->reference = $data['reference'];

				if ($job->due <= Carbon::now()) {
				    $job->save();
				    return ['Updated'];
				} else {
				    $job->save();
				    if ($changeDue['dateChanged']) $this->sendChangedDateNotification($job, $old_time);
				    if ($changeTranslator['translatorChanged']) $this->sendChangedTranslatorNotification($job, $current_translator, $changeTranslator['new_translator']);
				    if ($langChanged) $this->sendChangedLangNotification($job, $old_lang);
		}	

       			 }else{
        			abort(404, 'Job not found')
			}
    		}else{
    		abort(404, 'Parameters not found')
    		}
	
        }
    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
     
    private function changeStatus(Job $job, $data, $changedTranslator)
    {
    // Use job dependency injection first to user job in function paramter
        $old_statuses = $job->pluck('status')->toArray();
        if(count($old_statuses)>0){
        $statusChanged = false;
        foreach($old_statuses as old_status){
        // i will not recommend to use switch cases under psr-4 coding standards
        if ($old_status != $data['status']) {
            switch ($job->status) {
                case 'timedout':
                    $statusChanged = $this->changeTimedoutStatus($job, $data, $changedTranslator);
                    break;
                case 'completed':
                    $statusChanged = $this->changeCompletedStatus($job, $data);
                    break;
                case 'started':
                    $statusChanged = $this->changeStartedStatus($job, $data);
                    break;
                case 'pending':
                    $statusChanged = $this->changePendingStatus($job, $data, $changedTranslator);
                    break;
                case 'withdrawafter24':
                    $statusChanged = $this->changeWithdrawafter24Status($job, $data);
                    break;
                case 'assigned':
                    $statusChanged = $this->changeAssignedStatus($job, $data);
                    break;
                default:
                    $statusChanged = false;
                    break;
            }
            if ($statusChanged) {
                $log_data[] = [
                    'old_status' => $old_status,
                    'new_status' => $data['status']
                ];
                $statusChanged = true;
                
            }
	}
            
        }
        return ['log_data' => $log_data];
        }else{
        	abort(404, 'No Status found');
        }
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changeTimedoutStatus(Job $job, $data, $changedTranslator)
    {
    // Use job dependency injection first to user job in function paramter
    	$job = $job::findorFail($data['job_id']);
    	if($job && isset($data['status'] && isset($data['assigned']) && isset($data['pending']) && date('Y-m-d H:i:s') <= $job->due)
		{
		$old_status = $job->status;
		$job->status = $data['status'];
		$user = $job->user()->first();
			if (!empty($job->user_email)) {
			    $email = $job->user_email;
			} else {
			    $email = $user->email;
			}
			$name = $user->name;
			$dataEmail = [
			    'user' => $user,
			    'job'  => $job
			];
			if ($data['status'] == 'pending') {
			    $job->created_at = date('Y-m-d H:i:s');
			    $job->emailsent = 0;
			    $job->emailsenttovirpal = 0;
			    $job->save();
			    $job_data = $this->jobToData($job);

			    $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
			    $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);

			    $this->sendNotificationTranslator($job, $job_data, '*');   // send Push all sutiable translators

			    return true;
			} elseif ($changedTranslator) {
			    $job->save();
			    $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
			    $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
			    return true;
			}

	//        }
		return false;
    }
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeCompletedStatus(Job $job, $data)
    {
    // Use job dependency injection first to user job in function paramter
        if (in_array($data['status'] && $data['admin_comments'])) {
        $job->status = $data['status'];
        if ($data['status'] == 'timedout') {
            if ($data['admin_comments'] == '') return false;
            $job->admin_comments = $data['admin_comments'];
        }
        $job->save();
        return true;
        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeStartedStatus(Job $job, $data)
    {
    // Use job dependency injection first to user job in function paramter
        if (in_array($data['status'] && !empty($data['status'])) {
        $job->status = $data['status'];
        ($data['admin_comments'] == '') ? return false : $job->admin_comments = $data['admin_comments'];
     
        if ($data['status'] == 'completed') {
            $user = $job->user()->findOrFail();
            if ($data['sesion_time'] == '') return false;
            $diff = explode(':', $data['sesion_time']);
            $job->end_at = date('Y-m-d H:i:s');
            $job->session_time = $data['sesion_time'];
            $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';
            (!empty($job->user_email) ? $email = $job->user_email : $email = $user->email;
            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'faktura'
            ];

            $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);
            $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();
            $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'lön'
            ];
            $this->mailer->send($user->user->email, $user->name, $subject, 'emails.session-ended', $dataEmail);

        }
        $job->save();
        return true;
        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changePendingStatus(Job $job, $data, $changedTranslator)
    {
        if (in_array($data['status'] && !empty($data['status'])) {
        $job->status = $data['status'];
		($data['admin_comments'] == '' && $data['status'] == 'timedout') ? return false : $job->admin_comments = $data['admin_comments'];
		
        $user = $job->user()->findOrFail();
		(!empty($job->user_email)) ? $email = $job->user_email : $email = $user->email;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        if ($data['status'] == 'assigned' && isset($changedTranslator)) {

            $job->save();
            $job_data = $this->jobToData($job);

            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $user->name;, $subject, 'emails.job-accepted', $dataEmail);

            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

            $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
            $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
            return true;
        } else {
            $subject = 'Avbokning av bokningsnr: #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
            $job->save();
            return true;
        }


        }
        return false;
    }

    /*
     * TODO remove method and add service for notification
     * TEMP method
     * send session start remind notification
     */
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        $due_explode = explode(' ', $due);
        ($job->customer_physical_type == 'yes') ?  
        $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town . ') kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            ) : 
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min.Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeWithdrawafter24Status($job, $data)
    {
        if (in_array($data['status'], ['timedout']) && $data['admin_comments'] != '') {
            $job->status = $data['status'];
            $job->admin_comments = $data['admin_comments'];
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeAssignedStatus($job, $data)
    {
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout']) && $data['admin_comments'] != '' && $data['status'] != 'timedout') {
            $job->status = $data['status'];
            $job->admin_comments = $data['admin_comments'];
            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $user = $job->user()->findOrFail();

                (!empty($job->user_email)) ? $email = $job->user_email : $email = $user->email; 
                $name = $user->name;
                $dataEmail = [
                    'user' => $user,
                    'job'  => $job
                ];

                $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

                $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();
                $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);
            }
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * @param $current_translator
     * @param $data
     * @param $job
     * @return array
     */
    private function changeTranslator($current_translator, $data, $job)
    {
        $translatorChanged = false;

        if (isset($data['translator']) && $data['translator'] != 0 && isset($data['translator'] && $current_translator->user_id != $data['translator']) || $data['translator_email'] != '') {
            $log_data = [];
                $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $new_translator['user_id'] = $data['translator'];
                unset($new_translator['id']);
                $new_translator = Translator::create($new_translator);
                $current_translator->cancel_at = Carbon::now();
                $current_translator->save();
                $log_data[] = [
                    'old_translator' => $current_translator->user->email,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            }elseif (is_null($current_translator) && isset($data['translator']) && ($data['translator'] != 0 || $data['translator_email'] != '')) {
                if ($data['translator_email'] != '') 
                $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $new_translator = Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);
                $log_data[] = [
                    'old_translator' => null,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            }
            if ($translatorChanged)
                return ['translatorChanged' => $translatorChanged, 'new_translator' => $new_translator, 'log_data' => $log_data];

        }

        return ['translatorChanged' => $translatorChanged];
    }

    /**
     * @param $old_due
     * @param $new_due
     * @return array
     */
    private function changeDue($old_due, $new_due)
    {
        if ($old_due != $new_due) {
            $log_data = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];
            return ['dateChanged' => true, 'log_data' => $log_data];
        }

        return ['dateChanged' => false];

    }

    /**
     * @param $job
     * @param $current_translator
     * @param $new_translator
     */
    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
    {
        $user = $job->user()->findOrFail();
        $email=null;
        $subject=null;
        (!empty($job->user_email))? $email = $job->user_email : $email = $user->email;
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' . $job->id . ')';
        $data = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $user->name, $subject, 'emails.job-changed-translator-customer', $data);
        if ($current_translator) {
            $user = $current_translator->user;
            $data['user'] = $user;

            $this->mailer->send($user->email, $user->name;, $subject, 'emails.job-changed-translator-old-translator', $data);
        }
        $data['user'] = $user;
        $this->mailer->send($user->email, $user->name, $subject, 'emails.job-changed-translator-new-translator', $data);

    }

    /**
     * @param $job
     * @param $old_time
     */
    public function sendChangedDateNotification($job, $old_time)
    {
        $user = $job->user()->findOrFail();
        $email = null;
        (!empty($job->user_email)) ? $email = $job->user_email : $email = $user->email;
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send($email, $user->name, $subject, 'emails.job-changed-date', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $data['user'] = $translator;
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);

    }

    /**
     * @param $job
     * @param $old_lang
     */
    public function sendChangedLangNotification($job, $old_lang)
    {
        $user = $job->user()->first();
        if (!empty($job->user_email))? $email = $job->user_email : $email = $user->email;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_lang' => $old_lang
        ];
        $this->mailer->send($email, $user->name, $subject, 'emails.job-changed-lang', $data);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * Function to send Job Expired Push Notification
     * @param $job
     * @param $user
     */
    public function sendExpiredNotification($job, $user)
    {
        $data = array();
        $data['notification_type'] = 'job_expired';
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = array(
            "en" => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.'
        );
        $users_array = array($user);
        $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
    }

    /**
     * Function to send the notification for sending the admin job cancel
     * @param $job_id
     */
    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job = Job::findOrFail($job_id);
        if($job){
        $user_meta = $job->user->userMeta()->first();
        $data = array();            // save job's information to data for sending Push
        $data = $job->all()->toArray();

        $due_Date = explode(" ", $job->due);
        $data['due_date'] = $due_Date[0];
        $data['due_time'] = $due_Date[1];
        $data['job_for'] = array();
        ($job->gender == 'male')? $data['job_for'][] = 'Man': $data['job_for'][] = 'Kvinna';
            if ($job->certified == 'both') {
                $data['job_for'][] = 'normal';
                $data['job_for'][] = 'certified';
            } else if ($job->certified == 'yes') {
                $data['job_for'][] = 'certified';
            } else {
                $data['job_for'][] = $job->certified;
            }
        $this->sendNotificationTranslator($job, $data, '*');   // send Push all sutiable translators
        }else{
        return false;
    }

    /**
     * send session start remind notificatio
     * @param $user
     * @param $job
     * @param $language
     * @param $due
     * @param $duration
     */
    private function sendNotificationChangePending($user, $job, $language, $due, $duration)
    {
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        $msg_text = array(
                "en" => 'Du har nu fått platstolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
            );
        $this->bookingRepository->isNeedToSendPush($user->id)
        $users_array = array($user);
        $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
    }

    /**
     * making user_tags string from users array for creating onesignal notifications
     * @param $users
     * @return string
     */
    private function getUserTagsStringFromArray($users)
    {
        $user_tags = "[";
        $first = true;
        foreach ($users as $oneUser) {
            $user_tags .= ',{"operator": "OR"},{"key": "email", "relation": "=", "value": "' . strtolower($oneUser->email) . '"}';
        }
        $user_tags .= ']';
        return $user_tags;
    }

    /**
     * @param $data
     * @param $user
     */
    public function acceptJob($data, $user)
    {

        $adminemail = config('app.admin_email');
        $adminSenderEmail = config('app.admin_sender_email');

        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due) && $job->status == 'pending') {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->get()->first();
                $mailer = new AppMailer();
		$email = $user->email;
                $name = $user->name;
                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                $data = [
                    'user' => $user,
                    'job'  => $job
                ];
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);
            /*@todo
                add flash message here.
            */
            $jobs = $this->getPotentialJobs($cuser);
            $response = array();
            $response['list'] = json_encode(['jobs' => $jobs, 'job' => $job], true);
            $response['status'] = 'success';
        } else {
            $response['list'] = null;
            $response['status'] = '404';
            $response['message'] = 'Du har redan en bokning den tiden! Bokningen är inte accepterad.';
        }

        return $response;

    }

    /*Function to accept the job with the job id*/
    public function acceptJobWithId($job_id, $cuser)
    {
        $adminemail = config('app.admin_email');
        $adminSenderEmail = config('app.admin_sender_email');
        $job = Job::findOrFail($job_id);
        $response = array();

        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due) && $job->status == 'pending') {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->get()->first();
                $mailer = new AppMailer();
		$email = $job->user_email;
                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                $data = [
                    'user' => $user,
                    'job'  => $job
                ];
                $mailer->send($email, $user->name, $subject, 'emails.job-accepted', $data);

                $data = array();
                $data['notification_type'] = 'job_accepted';
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = array(
                    "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
                );
                $this->isNeedToSendPush($user->id)
                $this->sendPushNotificationToSpecificUsers($user, $job_id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
                }
                // Your Booking is accepted sucessfully
                $response['status'] = 'success';
                $response['list']['job'] = $job;
                $response['message'] = 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due;
        } else {
            // You already have a booking the time
            $response['status'] = 'fail';
            $response['list']['job'] = null;
            $response['message'] = '404 not found!';
        }
        return $response;
    }

    public function cancelJobAjax($data, $user)
    {
        $response = array();
        /*@todo
            add 24hrs loging here.
            If the cancelation is before 24 hours before the booking tie - supplier will be informed. Flow ended
            if the cancelation is within 24
            if cancelation is within 24 hours - translator will be informed AND the customer will get an addition to his number of bookings - so we will charge of it if the cancelation is within 24 hours
            so we must treat it as if it was an executed session
        */
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        if ($user->is('customer')) {
            $job->withdraw_at = Carbon::now();
            $job->status = 'withdrawafter24';
            $response['jobstatus'] = 'success';
            $job->save();
            Event::fire(new JobWasCanceled($job));
            $response['status'] = 'success';
            $response['jobstatus'] = 'success';
            if ($translator) {
                $data = array();
                $data['notification_type'] = 'job_cancelled';
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = array(
                    "en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
                );
                    $users_array = array($translator);
                    $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($translator->id));// send Session Cancel Push to Translaotor
            }
        } else {
            if ($job->due->diffInHours(Carbon::now()) > 24) {
                $customer = $job->user()findOrFail;
                if ($customer) {
                    $data = array();
                    $data['notification_type'] = 'job_cancelled';
                    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                    $msg_text = array(
                        "en" => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
                    );
                        $users_array = array($customer);
                        $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($customer->id));     // send Session Cancel Push to customer
                }
                $job->status = 'pending';
                $job->created_at = date('Y-m-d H:i:s');
                $job->will_expire_at = TeHelper::willExpireAt($job->due, date('Y-m-d H:i:s'));
                $job->save();
//                Event::fire(new JobWasCanceled($job));
                Job::deleteTranslatorJobRel($translator->id, $job_id);

                $data = $this->jobToData($job);

                $this->sendNotificationTranslator($job, $data, $translator->id);   // send Push all sutiable translators
                $response['status'] = 'success';
            } else {
                $response['status'] = 'fail';
                $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!';
            }
        }
        return $response;
    }

    /*Function to get the potential jobs for paid,rws,unpaid translators*/
    public function getPotentialJobs($cuser)
    {
        $job_type = 'unpaid';
        if ($cuser_meta->userMeta == 'professional')
            $job_type = 'paid';   /*show all jobs for professionals.*/
        else if ($translator_type == 'rwstranslator')
            $job_type = 'rws';  /$cuser_meta->userMeta* for rwstranslator only show rws jobs. */
        else if ($cuser_meta->userMeta == 'volunteer')
            $job_type = 'unpaid';  /* for volunteers only show unpaid jobs. */

        $languages = UserLanguages::where('user_id', '=', $cuser->id)->get();
        $userlanguage = collect($languages)->pluck('lang_id')->all();
        /*Call the town function for checking if the job physical, then translators in one town can get job*/
        $job_ids = Job::getJobs($cuser->id, $job_type, 'pending', $userlanguage, $cuser_meta->gender, $cuser_meta->translator_level);
        foreach ($job_ids as $k => $job) {
            $job->specific_job = Job::assignedToPaticularTranslator($cuser->id, $job->id);
            $job->check_particular_job = Job::checkParticularJob($cuser->id, $job);
            $checktown = Job::checkTowns($job->user_id, $cuser->id);

            if(($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && 
            $job->customer_physical_type == 'yes' && $checktown == false$job->specific_job == 'SpecificJob' && 
            $job->check_particular_job == 'userCanNotAcceptJob')
                unset($job_ids[$k]);
        }
//        $jobs = TeHelper::convertJobIdsInObjs($job_ids);
        return $job_ids;
    }

    public function endJob($post_data)
    {
        $jobid = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($jobid);

        if($job_detail->status != 'started')
            return ['status' => 'success'];
        $diff = date_diff(date_create(date('Y-m-d H:i:s')), date_create($job_detail->due));
        $job = $job_detail;
        $job_detail->end_at = date('Y-m-d H:i:s');
        $job_detail->status = 'completed';
        $job_detail->session_time =  $diff->h . ':' . $diff->i . ':' . $diff->s;;

        $user = $job_detail->user()->findOrFail();
        (!empty($job_detail->user_email))?$email = $job_detail->user_email:$email = $job_detail->email;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_explode = explode(':', $job->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'faktura'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $user->name, $subject, 'emails.session-ended', $data);

        $job->save();

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();

        Event::fire(new SessionEnded($job, ($post_data['user_id'] == $job->user_id) ? $tr->user_id : $job->user_id));

        $user = $tr->user()->first();
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'lön'
        ];
        $mailer = new AppMailer();
        $mailer->send($user->email, $user->name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = $completeddate;
        $tr->completed_by = $post_data['user_id'];
        $tr->save();
        $response['status'] = 'success';
        return $response;
    }


    public function customerNotCall($post_data)
    {
        $job_detail = Job::with('translatorJobRel')->find($post_data["job_id"]);
        $start = date_create($job_detail->due);
        $end = date_create(date('Y-m-d H:i:s'));
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job_detail->end_at = date('Y-m-d H:i:s');
        $job_detail->status = 'not_carried_out_customer';
        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();
        $tr->completed_at = $completeddate;
        $tr->completed_by = $tr->user_id;
        $job->save();
        $tr->save();
        $response['status'] = 'success';
        return $response;
    }

    public function getAll(GetAllRequest $request, $limit = null)
    {
            if ($limit == 'all') ? $allJobs->get() : $allJobs->paginate(15);
        return $allJobs;
    }

    public function alerts()
    {
        $jobs = Job::all();
        $sesJobs = [];
        $jobId = [];
        $diff = [];
        $i = 0;

        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time) && $diff[$i] >= $job->duration * 2;
            if (count($sessionTime) >= 3 && $diff[$i] >= $job->duration) {
                $diff[$i] = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);
            }
        }
        foreach ($sesJobs as $job) {
            $jobId [] = $job->id;
        }
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $request = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');
            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);
        }

        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $request];
    }

    public function userLoginFailed()
    {
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);

        return ['throttles' => $throttles];
    }

    public function bookingExpireNoAccepted()
    {
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $request = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');
            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);

        }
        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $request];
    }

    public function ignoreExpiring($id)
    {
        $job = Job::find($id);
        $job->ignore = 1;
        $job->save();
        return {'success':'Changes saved'};
    }

    public function ignoreExpired($id)
    {
        $job = Job::find($id);
        $job->ignore_expired = 1;
        $job->save();
        return {'success':'Changes saved'}
    }

    public function ignoreThrottle($id)
    {
        $throttle = Throttles::find($id);
        $throttle->ignore = 1;
        $throttle->save();
        return {'success':'Changes saved'}
    }

    public function reopen($request)
    {
        $userid = $request['userid'];
        $job = Job::findOrFail($request['jobid']);
        $job = $job->toArray();
        $data = array();
        $data['user_id'] = $userid;
        $data['job_id'] = $jobid;
        $data['cancel_at'] = Carbon::now();
        if ($job['status'] != 'timedout') {
            $affectedRows = Job::where('id', '=', $jobid)->update($datareopen);
            $new_jobid = $jobid;
        } else {
            $job['status'] = 'pending';
            $job['created_at'] = Carbon::now();
            $job['updated_at'] = Carbon::now();
            $job['will_expire_at'] = TeHelper::willExpireAt($job['due'], date('Y-m-d H:i:s'));
            $job['updated_at'] = date('Y-m-d H:i:s');
            $job['cust_16_hour_email'] = 0;
            $job['cust_48_hour_email'] = 0;
            $job['admin_comments'] = 'This booking is a reopening of booking #' . $jobid;
            //$job[0]['user_email'] = $user_email;
            $affectedRows = Job::create($job);
            $new_jobid = $affectedRows['id'];
        }
        //$result = DB::table('translator_job_rel')->insertGetId($data);
        Translator::where('job_id', $jobid)->where('cancel_at', NULL)->update(['cancel_at' => $data['cancel_at']]);
        $Translator = Translator::create($data);
        if (isset($affectedRows)) {
            $this->sendNotificationByAdminCancelJob($new_jobid);
            return ["Tolk cancelled!"];
        } else {
            return ["Please try again!"];
        }
    }

    /**
     * Convert number of minutes to hour and minute variant
     * @param  int $time   
     * @param  string $format 
     * @return string         
     */
    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return $time . 'min';
        } else if ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = ($time % 60);
        $data = array();
        return $data[$format, $hours, $minutes];
    }

}
