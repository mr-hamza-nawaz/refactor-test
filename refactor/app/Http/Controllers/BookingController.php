<?php

namespace DTApi\Http\Controllers;

use DTApi\Models\Job,Distance;
use DTApi\Http\Requests\GetBookingRequest,StoreBookingRequest;
use DTApi\Repository\BookingRepository;
use App\Http\Controllers\ApiController;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends ApiController
{

    /**
     * @var BookingRepository
     */
    use JsonResponse;
    //First change is to change repository variable into readable form which is $bookings
    protected $bookings;

    /**
     * BookingController constructor.
     * @param BookingRepository $bookingRepository
     */
    public function __construct(BookingRepository $bookingRepository)
    {
        $this->bookings = $bookingRepository;
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function index(GetBookingRequest $request)
    {
        DB::beginTransaction();
        try{
            if(Auth::user && $request->__authenticatedUser->user_type != env('ADMIN_ROLE_ID') || $request->__authenticatedUser->user_type != env('SUPERADMIN_ROLE_ID')) {

                $response = $this->bookings->getUsersJobs(Auth::user()->id);

            }
            else
            {
                $response = $this->bookings->getAll();
            }
            DB::commit();
        }
            catch(\Exception $e) {
                DB::rollback();
                return errorResponse('error',$e->getMessage(), 500);
            }
        return $this->successResponse($response,'Jobs get captured successfully', 200);
    }

    /**
     * @param $id
     * @return mixed
     */
    public function show($id)
    {
        DB::beginTransaction();
            try{
                $job = $this->bookings->with('translatorJobRel.user')->findOrFail($id);
                DB::commit();
            }
            }catch(\Exception $e) {
                DB::rollback();
                return errorResponse('error',$e->getMessage(), 500);
            }
        
        return ($job) ? (successResponse($job,'Jobs get captured successfully', 200)) : (errorResponse(null,'Jobs not found..!', 404));
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function store(StoreorUpdateBookingRequest $request)
    {
        DB::beginTransaction();
            try{
                if(Auth::User){
                $response = $this->bookings->create($request->all());
                return successResponse($job,'Booking is created successfully..!', 201)
                }else{  
                    return errorResponse(null,'Login user is not validated..!', 404);
                }
                DB::commit();
            }
            catch(\Exception $e) {
                DB::rollback();
                return errorResponse('error',$e->getMessage(), 500);
        }

    }

    /**
     * @param $id
     * @param Request $request
     * @return mixed
     */
    public function update(StoreorUpdateBookingRequest $request,$id)
    {
        DB::beginTransaction();
        $booking =  $this->bookings->findorFail($id);
            try{
                if($booking && Auth::User){
                    $data = array_merge($request->all(),$Auth::user)
                    $this->bookings->updateJob($data,$id);
                    return successResponse(null,'Booking is updated successfully..!', 201);
                }else{  
                    return errorResponse(null,'Login user is not validated..!', 404);
                }
                DB::commit();
            }
            catch(\Exception $e) {
                DB::rollback();
                return errorResponse('error',$e->getMessage(), 500);
        }
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function immediateJobEmail(Request $request)
    {
        DB::beginTransaction();
            try{
                if($request->all()){
                $adminSenderEmail = config('app.adminemail');
                $response = $this->bookings->storeJobEmail($request->all());
                DB::commit();
                return successResponse($response,'Email job is created successfully..!', 201);
                }else{
                    return errorResponse($response,'Request is not valid', 404);
                }
            }
            catch(\Exception $e) {
                DB::rollback();
                return errorResponse('error',$e->getMessage(), 500);
        }
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getHistory(Request $request)
    {

        DB::beginTransaction();
            try{
                if(Auth::user->id) {

                    $response = $this->bookings->getUsersJobsHistory($user_id, $request->all());
                    return successResponse($response,'User jobs history captured successfully', 200);
                    DB::commit();
                }else{
                    return errorResponse(null,'User jobs history not found', 404);
                }
            }
            catch(\Exception $e) {
                    DB::rollback();
                    return errorResponse('error',$e->getMessage(), 500);
        }
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function acceptJob(Request $request)
    {
        DB::beginTransaction();
            try{
                if(Auth::user()) {

                    $response = $this->bookings->acceptJob($request->all(), Auth::user());
                    return successResponse($response,'User jobs history captured successfully', 200);
                }else{
                    return errorResponse(null,'User is not valid to perform certain operation', 404);
                }
                DB::commit();
            }
            catch(\Exception $e) {
                    DB::rollback();
                    return errorResponse('error',$e->getMessage(), 500);
        }
    }

    public function acceptJobWithId(Request $request)
    {
        DB::beginTransaction();
            try{
                if(Auth::user()) {

                    $response = $this->bookings->acceptJobWithId($request->job_id, Auth::user());
                    return successResponse($response,'User jobs history captured successfully', 200);
                }else{
                    return errorResponse(null,'Job is not valid to perform certain operation', 404);
                }
                DB::commit();
            }
            catch(\Exception $e) {
                    DB::rollback();
                    return errorResponse('error',$e->getMessage(), 500);
        }
    
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function cancelJob(Request $request)
    {
        DB::beginTransaction();
        try{
            if(Auth::user()) {

                $response = $this->bookings->cancelJobAjax($request->all());
                return successResponse($response,'User jobs history captured successfully', 200);
            }else{
                return errorResponse(null,'Job is not valid to perform certain operation', 404);
            }
            DB::commit();
        }
        catch(\Exception $e) {
                DB::rollback();
                return errorResponse('error',$e->getMessage(), 500);
    }
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function endJob(Request $request)
    {

        DB::beginTransaction();
        try{
            if(Auth::user()) {

                $response = $this->bookings->endJob($request->all());
                return successResponse($response,'User jobs history captured successfully', 200);
            }else{
                return errorResponse(null,'Job is not valid to perform certain operation', 404);
            }
            DB::commit();
        }
        catch(\Exception $e) {
                DB::rollback();
                return errorResponse('error',$e->getMessage(), 500);
    }

    }

    public function customerNotCall(Request $request)
    {
        DB::beginTransaction();
        try{
            if(Auth::user()) {

                $response = $this->bookings->customerNotCall($request->all());
                return successResponse($response,'User jobs history captured successfully', 200);
            }else{
                return errorResponse(null,'Job is not valid to perform certain operation', 404);
            }
            DB::commit();
        }
        catch(\Exception $e) {
                DB::rollback();
                return errorResponse('error',$e->getMessage(), 500);
    }

    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getPotentialJobs(Request $request)
    {
        DB::beginTransaction();
        try{
            if(Auth::user()) {

                $response = $this->bookings->getPotentialJobs($request->all());
                return successResponse($response,'User jobs history captured successfully', 200);
            }else{
                return errorResponse(null,'Job is not valid to perform certain operation', 404);
            }
            DB::commit();
        }
        catch(\Exception $e) {
                DB::rollback();
                return errorResponse('error',$e->getMessage(), 500);
    }
    }

    public function distanceFeed(DistanceFeedRequest $request)
    {
        DB::beginTransaction();
        try{
            if(Auth::hasRole('admin*any')) {
                
                $affectedRows1 = Distance::where('job_id', '=', $request->job_id)->update(array('distance' => $request->distance, 'time' => $request->timezone_version_get));
                $affectedRows2 = Job::where('id', '=', $request->job_id)->update(
                array('admin_comments' => $request->admincomment, 'flagged' => $flagged, 'session_time' => $request->session_time, 'manually_handled' => $request->manually_handled, 'by_admin' => $request->by_admin)
        );
                return successResponse("success",'Rows has been updated successfully', 201);
            }else{
                return errorResponse("error",'Rows has been updated successfully', 404);
            }
            DB::commit();
        }
        catch(\Exception $e) {
                DB::rollback();
                return errorResponse('error',$e->getMessage(), 500);
    }
    }

    public function reopen(Request $request)
    {
        DB::beginTransaction();
        try{
            if(Auth::user()) {

                $response = $this->bookings->reopen($request->all()a);
                return successResponse($response,'Reopen operation has been performed successfully', 200);
            }else{
                return errorResponse(null,'Reopen operation is invalid', 404);
            }
            DB::commit();
        }
        catch(\Exception $e) {
                DB::rollback();
                return errorResponse('error',$e->getMessage(), 500);
    }
    }

    public function resendNotifications(Request $request)
    {
        DB::beginTransaction();
        try{
            if(Auth::user() && $this->bookings->findorFail($request->jobid);) {
                $job_data = $this->bookings->jobToData($request->all());
                $this->bookings->sendNotificationTranslator($job, $job_data, '*');
                return successResponse($response,'Notification has been sent succesfully..!', 200);
            }else{
                return errorResponse(null,'Notification operation is invalid..!', 404);
            }
            DB::commit();
        }
        catch(\Exception $e) {
                DB::rollback();
                return errorResponse('error',$e->getMessage(), 500);
    }

    }

    /**
     * Sends SMS to Translator
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function resendSMSNotifications(Request $request)
    {
        DB::beginTransaction();
        try{
            if(Auth::user() && $this->bookings->findorFail($request->jobid);) {
                $data = $request->all();
                $job = $this->bookings->find($data['jobid']);
                $job_data = $this->bookings->jobToData($job);
                $this->bookings->sendSMSNotificationToTranslator($job);
                return successResponse($response,'Resend Notification has been sent succesfully..!', 200);
            }else{
                return errorResponse('error','Resend Notification operation is invalid..!', 404);
            }
            DB::commit();
        }
        catch(\Exception $e) {
                DB::rollback();
                return errorResponse('error',$e->getMessage(), 500);
    }
    }


