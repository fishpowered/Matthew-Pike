<?php
namespace Tyoaika\activityReporting\controllers;
use Tyoaika\common\classes\Authentication;
use Tyoaika\common\classes\Controller;
use Request;
use Tyoaika\common\classes\Filter;
use Tyoaika\common\classes\Locale;
use Tyoaika\common\classes\TimeSpan;
use Tyoaika\common\classes\Url;
use Tyoaika\common\classes\responses\ViewAsDownloadableFileResponse;
use Tyoaika\common\classes\User;

/**
 * Returns a user's events in the specified calendar format to be used for syncing
 * e.g. Google calendar can use this address to automatically fetch the latest events for a user.
 *
 * @package Tyoaika\activityReporting\controllers
 */
class GetEventsAsExternalCalendarFormatController extends Controller {

	/**
	 * Authenticated user for this controller only
	 *
	 * @var User
	 */
	private $authenticatedUser;

	/**
	 * Because this controller gets accessed directly from external calendar services,
	 * we must authenticate based on a key provided in the request.
	 *
	 * @return bool
	 * @throws \BadRequestException
	 * @throws \PermissionException
	 * @throws \Exception
	 */
	public function authoriseAccess(): bool {
		$request = $this->getServiceContainer()->getRequest();
		$result = $this->getServiceContainer()->getAuthentication()->authenticateExternalCalendarAccessHash((int) $request->get('uid'), (string) $request->get('key'));
		if($result instanceof User){
			$this->authenticatedUser = $result; // we shouldn't gift the calling client full logged in rights, just rights to this controller
			return true;
		}
		switch($result){
			case Authentication::FAIL_INVALID_CREDENTIALS:
				throw new \BadRequestException(); // This should 404 and tell the client calendar service that they are using a bad request and hopefully they won't keep spamming the service
			case Authentication::FAIL_ACCOUNT_DISABLED_OR_DELETED:
			case Authentication::FAIL_LOGIN_LOCKED:
				throw new \PermissionException(); // Dispatcher can turn this into a permission denied response code to inform the client
			default:
				throw new \SetupException('Please handle authentication response: '.$result);
		}
	}

	/**
	 * Returns the users activities in the ICAL format
	 *
	 * @param Request $request
	 * @return ViewAsDownloadableFileResponse
	 * @throws \SecurityException
	 * @throws \Exception
	 */
	public function getActivitiesAsICalendarAction(Request $request) : ViewAsDownloadableFileResponse{

		if(!$this->authenticatedUser){
			throw new \SetupException('authoriseAccess must be called first with a valid authentication key');
		}

		// Init dependencies
		$translator         = $this->getServiceContainer()->getTranslator();
		$currentTimeStamp   = $this->getServiceContainer()->getCurrentTimeStamp();
		$activityRepository = $this->getServiceContainer()->getActivityRepository();
		$customerRepository = $this->getServiceContainer()->getCustomerRepository();
		$serviceUrl			= $this->getServiceContainer()->getDotNetDomain();
		$serviceProvider	= $this->getServiceContainer()->getServiceProvider();
		$customer			= $customerRepository->selectCustomerByCustomerId($this->authenticatedUser->getCustomerId());
		$locale				= $this->getServiceContainer()->getSettingValueProxy()->getSettingValueForUser($this->authenticatedUser, 'CultureInfo', null, 1, Locale::DEFAULT);
		$translator->setLocale($locale);
		if(!$customer){
			throw new \LogicException('Customer "'.$this->authenticatedUser->getCustomerId().'" not found for authenticated user "'.$this->authenticatedUser->getId().'"');
		}

		// Init calendar object
		$syncTimeSpan  			= new TimeSpan(date('Y-m-d', strtotime('-7 days', $currentTimeStamp)), date('Y-m-d', strtotime('+36 months', $currentTimeStamp)));
		$calendarTitle 			= $customer->getName().' - '. $this->authenticatedUser->getUserName(); // Some people might have multiple user accounts for a company if they work multiple roles, I think customer and username are the best way to distinguish
		$calendarDescription 	= $serviceUrl . ' - '.$serviceProvider;
		$makePrivate			= $request->hasPositiveUserValue('makePrivate', false); // Note: this works in a dumb way in Google calendar (i.e. you cannot see the events you imported yourself as they are private) so best to leave it as false
		if(\trim($request->get('activityTypeFilter'))!==''){
			$activityTypeFilter = explode('_', $request->get('activityTypeFilter'));
		}else{
			$activityTypeFilter = [];
		}
		$targetCalendar = $request->get('cal');
		$timeZone = $request->get('tz', 'Europe/Helsinki'); // Google calendar doesn't support importing date times without the timezone so let's allow the timezone to be set in the request
		$iCalendar = $activityRepository->getActivitiesAsICalendar($calendarTitle, $calendarDescription, $this->authenticatedUser, $syncTimeSpan, $activityTypeFilter, $makePrivate, $translator, $timeZone);

		// Return response as a downloadable calendar ics file
		$fileName           = Filter::fileName($calendarTitle) . '.ics';
		$response           = new ViewAsDownloadableFileResponse($fileName, false);
		$renderedICalExport = $iCalendar->render();
		$response->setBody($renderedICalExport);
		// NOTE WHEN TESTING THIS, OFFICE 365 WON'T READ ICS FILES FROM THE PTS SERVER. I THOUGHT THIS WAS DUE TO A HEADER ISSUE BUT APPEARS IT'S A SERVER THING AS WORKS JUST FINE ON PROD
		$response->addHeader('Content-disposition: inline; filename="' . $fileName . '"');
		$response->addHeader('Content-Type: text/calendar');
		$response->addHeader('access-control-allow-origin: *');
		$response->addHeader('accept-ranges: bytes;');
		$response->addHeader('cache-control: max-age=600');
		$response->addHeader('age: 0');
		$response->addHeader('content-length: '.strlen($renderedICalExport)); // note: do not use mb_strlen as we actually want the number of bytes, not the number of chars
		$response->addHeader('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', $this->getServiceContainer()->getCurrentTimeStamp() + (6 * 3600)));
		return $response;
	}

	/**
	 * Get URL for getActivitiesAsICALAction. This is the URL that will be passed to an external calendar service!
	 *
	 * @param string $baseUrl
	 * @param int $userId
	 * @return Url
	 */
	public static function getUrlForICalendarFormat(string $baseUrl, int $userId) : Url{
		$url = new Url([
			'uid' => $userId,
			'activityTypeFilter' => '_activityTypeFilter_',
			'cal' => '_externalCalendarType_',
			// 'makePrivate' => '_makePrivate_', this doesn't work well with Google calendar so just going to omit it
			'key' => Authentication::generateExternalCalendarAccessHashKey($userId),
		]);
		$baseUrl = trim($baseUrl, '/');
		$url->setBaseUrl($baseUrl.'/cal/');
		return $url;
	}
}