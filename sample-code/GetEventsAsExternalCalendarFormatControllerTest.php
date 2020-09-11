<?php
// Unhandled exceptions inspection isn't relevant in a unit test class
/** @noinspection PhpDocMissingThrowsInspection */
/** @noinspection PhpUnhandledExceptionInspection */

use Tyoaika\activityReporting\classes\{Activity, ActivityRepository};
use Tyoaika\activityReporting\controllers\GetEventsAsExternalCalendarFormatController;
use Tyoaika\common\classes\Authentication;
use Tyoaika\common\classes\responses\ViewAsDownloadableFileResponse;
use TyoaikaTesting\unit_tests\TestCase;

/**
 * Class GetEventsAsExternalCalendarFormatControllerTest
 */
class GetEventsAsExternalCalendarFormatControllerTest extends TestCase {

	/**
	 * @inheritdoc
	 */
	public function setUp(): void {
		$this->cleanUp();
		parent::setUp();
	}

	/**
	 * @inheritdoc
	 */
	protected function tearDown(): void {
		$this->cleanUp();
		parent::tearDown();
	}

	/**
	 * Clean up changed unit test data
	 */
	private function cleanUp(): void {
		$activityRepository = new ActivityRepository($this->db);
		$activityRepository->permanentlyDeleteActivities(18506, true); // UT_Vanilla - "ACTIVITYRECORDING UNITTEST" Activities are deleted before+after tests
	}

	/**
	 * Because an external calendar will be calling this controller, the authentication must occur with each request based on a key in the URL
	 *
	 * @covers GetEventsAsExternalCalendarFormatController::authoriseAccess
	 */
	public function testAuthoriseAccess_NoUrlAuthKeyProvided(): void {
		$this->expectException(BadRequestException::class);
		$_SESSION['userId'] = 4766; // decoy authentication, the session should NOT be considered, only the URL key
		$serviceContainer = new ServiceContainer();
		$serviceContainer->setRequest(new Request());
		$controller = new GetEventsAsExternalCalendarFormatController($serviceContainer);
		$controller->authoriseAccess(); // no key or uid, throw BadRequestException
	}

	/**
	 * Because an external calendar will be calling this controller, the authentication must occur with each request based on a key in the URL
	 *
	 * @covers GetEventsAsExternalCalendarFormatController::authoriseAccess
	 */
	public function testAuthoriseAccess_InvalidAuthKeyProvided(): void {
		$this->expectException(BadRequestException::class);
		$_SESSION['userId'] = 4766; // decoy authentication, the session should NOT be considered, only the URL key
		$serviceContainer = new ServiceContainer();
		$serviceContainer->setRequest(new Request(['key'=>'bogusKey', 'uid'=>4766]));
		$controller = new GetEventsAsExternalCalendarFormatController($serviceContainer);
		$controller->authoriseAccess(); // no key or userid, throw BadRequestException
	}

	/**
	 * Because an external calendar will be calling this controller, the authentication must occur with each request based on a key in the URL
	 *
	 * @covers GetEventsAsExternalCalendarFormatController::authoriseAccess
	 */
	public function testAuthoriseAccess_ValidAuthKeyProvided(): void {
		$serviceContainer = new ServiceContainer();
		$serviceContainer->setRequest(new Request(['key'=>Authentication::generateExternalCalendarAccessHashKey(4766), 'uid'=>4766]));
		$controller = new GetEventsAsExternalCalendarFormatController($serviceContainer);
		$this->assertTrue($controller->authoriseAccess());
	}

	/**
	 * @covers GetEventsAsExternalCalendarFormatController::getActivitiesAsICalendarAction
	 */
	public function testGetActivitiesAsICalendarAction_ActionCalledWithoutAuthentication(): void {
		$this->expectException(SetupException::class);
		$_SESSION['userId'] = 4766; // decoy authentication, the session should NOT be considered, only the URL key
		$serviceContainer = new ServiceContainer();
		$serviceContainer->setAuthenticatedUser($this->userRepository->selectUserByUserId(4766)); // more decoy authentication that should be ignored in favour of the request key
		$request          = new Request();
		$serviceContainer->setRequest($request);
		$controller = new GetEventsAsExternalCalendarFormatController($serviceContainer);
		$controller->getActivitiesAsICalendarAction($request); // authoriseAccess has not been called first, throw SetupException
	}

	/**
	 * @covers GetEventsAsExternalCalendarFormatController::getActivitiesAsICalendarAction
	 */
	public function testGetActivitiesAsICalendarAction_ActionCalledWithCorrectAuthentication(): void {
		$testUser = $this->userRepository->selectUserByUserId(18506); // UT_Vanilla - "ACTIVITYRECORDING UNITTEST" Activities are deleted before+after tests
		$serviceContainer = new ServiceContainer();
		$userLocale = 'sv_SE';
		$serviceContainer->getSettingValueProxy()->fixSettingValue($testUser->getId(), 'CultureInfo', $userLocale);
		$request = new Request([
			'key' => Authentication::generateExternalCalendarAccessHashKey($testUser->getId()),
			'uid' => $testUser->getId(),
			'activityTypeFilter' => ACTIVITY_TYPE_PLANNED_WORK.'_'.ACTIVITY_TYPE_TRAINING_ID,
			'makePrivate' => 0,
			'cal' => 'google' // makes no difference to result but we provide the info in the link just in case it does at some point
		]);
		$serviceContainer->setRequest($request);
		$serviceContainer->setWtDomain('https://time.nepton.com');
		$controller = new GetEventsAsExternalCalendarFormatController($serviceContainer);
		$this->assertTrue($controller->authoriseAccess());

		// Create some test activities to return
		$activityRepository = $serviceContainer->getActivityRepository();
		$this->db->beginTransaction();
		$work = new Activity(ACTIVITY_TYPE_WORK_ID); // don't export because of type
		$work->setUser($testUser);
		$work->setBeginDateTime(date('Y-m-d 08:00:00', strtotime('+2 day')));
		$work->setEndDateTime(date('Y-m-d 17:00:00', strtotime('+2 day')));
		$activityRepository->save($work, $testUser);

		$training = new Activity(ACTIVITY_TYPE_TRAINING_ID); // do export because of type and date
		$training->setUser($testUser);
		$training->setBeginDateTime(date('Y-m-d 10:00:00', strtotime('+7 day')));
		$training->setEndDateTime(date('Y-m-d 12:00:00', strtotime('+7 day')));
		$training->setDescription('Test comment');
		$training->setRelatedProjectIds([$serviceContainer->getProjectRepository()->getProjectByCode('16121/20396', $testUser->getCustomerId())->getId()]);
		$activityRepository->save($training, $testUser);

		$shift = new Activity(ACTIVITY_TYPE_PLANNED_WORK); // do export because of type and date
		$shift->setUser($testUser);
		$shift->setBeginDateTime(date('Y-m-d 22:00:00', strtotime('+100 day')));
		$shift->setEndDateTime(date('Y-m-d 06:00:00', strtotime('+101 day')));
		$activityRepository->save($shift, $testUser);

		$shiftInPast = new Activity(ACTIVITY_TYPE_PLANNED_WORK); // we don't want to sync types in the past, even if they are in the activityTypeFilter list
		$shiftInPast->setUser($testUser);
		$shiftInPast->setBeginDateTime(date('Y-m-d 18:00:00', strtotime('-10 day')));
		$shiftInPast->setEndDateTime(date('Y-m-d 22:00:00', strtotime('-10 day')));
		$activityRepository->save($shiftInPast, $testUser);

		// Run our test
		$response = $controller->getActivitiesAsICalendarAction($request);
		$this->db->rollBack();

		/** @noinspection UnnecessaryAssertionInspection */
		$this->assertInstanceOf(ViewAsDownloadableFileResponse::class, $response);
		$this->assertEquals('ut_vanilla_unittest-activityrec.ics', $response->getFileName());
		$renderedResponse = $response->render();

		// We use a library to generate the ICS format which has more unit tests but this tests the basics
		$this->assertStringContainsString('BEGIN:VCALENDAR'."\r\n", $renderedResponse);
		$this->assertStringContainsString('PRODID:Nepton'."\r\n", $renderedResponse);
		$this->assertStringContainsString('X-WR-CALNAME:UT_Vanilla - UNITTEST ACTIVITYREC'."\r\n", $renderedResponse);
		$this->assertStringContainsString('X-WR-CALDESC:https://go.nepton.com - Nepton'."\r\n", $renderedResponse);
		$this->assertStringContainsString('X-WR-RELCALID:nepton-wt-user-18506'."\r\n", $renderedResponse);
		$this->assertStringContainsString('END:VCALENDAR', $renderedResponse);

		// Test the training is exported as required
		$expectedTrainingLines = [
			'BEGIN:VEVENT',
			'UID:' .$training->getId(),
			'DTSTART:' . gmdate('Ymd\THis\Z', $training->getBeginTimeStamp()), // important we export in UTC (donated with the Z at th end) otherwise the times will be incorrect in Google cal
			'SEQUENCE:0',
			'TRANSP:OPAQUE',
			'DTEND:' . gmdate('Ymd\THis\Z', $training->getEndTimeStamp()), // important we export in UTC (donated with the Z at th end) otherwise the times will be incorrect in Google cal
			'SUMMARY:Skolning - Test comment', // translation should be done based on setting for user authenticated in URL. This has been set to Swedish above so we know we are not just defaulting to Finnish
			'CLASS:PUBLIC',						// if you export as private, google calendar won't let you see the details of your own events :)
			'DESCRIPTION:Händelsetyp: Skolning\n \nBeskrivning: Test comment\n \nProjek',
			' t: \n- 20396/G4S Lukkoasema Oy',
			'CATEGORIES:Skolning',
		];
		$this->assertStringContainsString(implode("\r\n", $expectedTrainingLines), $renderedResponse);

		// Test the valid shift is exported as required
		$expectedPlannedWorkLines = [
			'BEGIN:VEVENT',
			'UID:' .$shift->getId(),
			'DTSTART:' . gmdate('Ymd\THis\Z', $shift->getBeginTimeStamp()), // important we export in UTC (donated with the Z at th end) otherwise the times will be incorrect in Google cal
			'SEQUENCE:0',
			'TRANSP:OPAQUE',
			'DTEND:' . gmdate('Ymd\THis\Z', $shift->getEndTimeStamp()), // important we export in UTC (donated with the Z at th end) otherwise the times will be incorrect in Google cal
			'SUMMARY:Planerad arbetstur', // translation should be done based on setting for user authenticated in URL. This has been set to Swedish above so we know we are not just defaulting to Finnish
			'CLASS:PUBLIC',
			'DESCRIPTION:Händelsetyp: Planerad arbetstur\n \n ',
			'CATEGORIES:Planerad arbetstur',
		];

		$this->assertStringContainsString(implode("\r\n", $expectedPlannedWorkLines), $renderedResponse);

		// Check there are no other events added
		$this->assertCount(3, explode('BEGIN:VEVENT', $renderedResponse), $renderedResponse);
	}

	/**
	 * @covers GetEventsAsExternalCalendarFormatController::getUrlForICalendarFormat
	 */
	public function testGetUrlForICalendarFormat_ActionCalledWithCorrectAuthentication(): void {
		$url = GetEventsAsExternalCalendarFormatController::getUrlForICalendarFormat('http://foo.bar/', 123);
		$this->assertEquals(
			'http://foo.bar/cal/?activityTypeFilter=_activityTypeFilter_&cal=_externalCalendarType_&key='.Authentication::generateExternalCalendarAccessHashKey(123).'&uid=123',
			$url->render(false, true)
		);
	}
}
