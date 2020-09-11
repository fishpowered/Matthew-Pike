<?php
// Unhandled exceptions inspection isn't relevant in a unit test class
/** @noinspection PhpDocMissingThrowsInspection */
/** @noinspection PhpUnhandledExceptionInspection */

namespace TyoaikaTesting\unit_tests\modules\activityReporting\salaryEngine\salaryConcepts;

use ActivityCustomPeriod;
use SettingValueProxy;
use TimeSpanAccrual;
use TimeSpanBasicTime;
use TimeSpanOvertimeAdditionalWork;
use TimeSpanOvertimeDaily100;
use TimeSpanOvertimeDaily50;
use TimeSpanPaidTime;
use TimeSpanUnpaidOvertime;
use TimeSpanWorkRise;
use TimeSpanWorkRiseActive;
use TimeSpanWorkRiseNegativePercentagePartByRiseNumber;
use TimeSpanWorkRisePercentagePartByRiseNumber;
use TyoaikaTesting\unit_tests\TestCase;
use Tyoaika\activityReporting\classes\Activity;
use Tyoaika\common\classes\{TimeSpan, User};
use Tyoaika\activityReporting\classes\ActivityPayTarget;
use function end;
use const ACTIVITY_TYPE_INMADE_FREE_ID;
use const ACTIVITY_TYPE_PLANNED_WORK;
use const ACTIVITY_TYPE_SICK_LEAVE_ID;
use const ACTIVITY_TYPE_WORK_HOUR_BANK_LEAVE_INTERNAL_NAME;
use const ACTIVITY_TYPE_WORK_ID;
use const ACTIVITY_TYPE_YEAR_LEAVE_ID;
use const CUSTOMER_EPILEPSIALIITTO_ID;
use const CUSTOMER_LINKOSUO_ID;
use const ORIGINATED_BASIC_SALARY_TIME;
use const ORIGINATED_BASIC_TIME_FORCED_TO_SALARY_TYPE_BY_WORK_RISE;
use const ORIGINATED_BORROWED_FROM_ACCRUAL;
use const ORIGINATED_MINIMUM_WORK_TIME_COMPENSATION;
use const ORIGINATED_NON_ADDITIONAL_OVERTIME_FORCED_TO_SALARY_TYPE_BY_WORK_RISE;
use const ORIGINATED_OVERTIME_ADDITIONAL_WORK;
use const ORIGINATED_OVERTIME_DAILY_100_PERCENT;
use const ORIGINATED_OVERTIME_DAILY_50_PERCENT;
use const ORIGINATED_OVERTIME_SUNDAY_PUBLIC_HOLIDAY_100_PERCENT;
use const ORIGINATED_OVERTIME_SUNDAY_PUBLIC_HOLIDAY_50_PERCENT;
use const ORIGINATED_OVERTIME_WEEKLY_100_PERCENT;
use const ORIGINATED_OVERTIME_WEEKLY_50_PERCENT;
use const ORIGINATED_SICK_LEAVE_TYPE;
use const ORIGINATED_UNPAID_OVERTIME_FORCED_TO_SALARY_TYPE_BY_WORK_RISE;
use const SALARY_RENDERING_MODE_BY_DAY;
use const SALARY_RENDERING_MODE_BY_SHIFT_END;
use const SALARY_RENDERING_MODE_BY_SHIFT_START;

/**
 * Unit test work rises calculation TimeSpans
 *
 * @author matt.pike
 */
class SalaryConceptWorkRisesTest extends TestCase {

	/**
	 * covers SalaryConceptWorkRises::process
	 * @link https://gemini.nepton.com/issue/ViewIssue.aspx?id=7133&PROJID=11 NEPDEV-7133
	 * @return void
	 */
	public function testWorkRiseEarnedTwiceFromLunchDuringOvertimeBug(): void {
		
		$user = $this->userRepository->selectUserByUserId(10691);
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		$activityDay = $activityCustomPeriod->getActivityDay('2013-04-19');
		
		# The work rise (#8 - Vuorovastaavanlisä) is active all day and they get paid lunch, so the user should earn the same amount of work rise as they have work hours (10:27)
		$this->assertEquals((10 * 3600) + (27 * 60), $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number'=>8]));
	}
	
	/**
	 * covers SalaryConceptWorkRises::process
	 * @link https://gemini.nepton.com/workspace/0/item/9864 NEPDEV-9864
	 * @return void
	 */
	public function testWorkRiseNotEarnedDuringOvertimeWhenConfiguredNotTo(): void {
	
		$user = $this->userRepository->selectUserByUserId(20573);
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		$activityDay = $activityCustomPeriod->getActivityDay('2013-04-19');
	
		# The work rise (#1 - Lauantaityö) is flagged not to be earned during overtime, and as its a Saturday and they are doing overtime, no work rise should be earned
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number'=>1]));
	}
	
	/**
	 * covers SalaryConceptWorkRises::process
	 * @link https://gemini.nepton.com/workspace/0/item/5445 NEPDEV-5445
	 * @return void
	 */
	public function testNEPDEV_5445(): void {
	
		$user = $this->userRepository->selectUserByUserId(10687);
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		$activityDay = $activityCustomPeriod->getActivityDay('2012-06-17');
	
		# Both work rises are active all day and they get paid lunch, so the user should earn the same amount of work rise as they have work hours, 08:09 (hours are limited by work time limits)
		$this->assertEquals((8 * 3600) + (9 * 60), $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number'=>3]));
		$this->assertEquals((8 * 3600) + (9 * 60), $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number'=>7]));
	}
	
	/**
	 * covers SalaryConceptWorkRises::process
	 * @link https://gemini.nepton.com/workspace/0/item/7621 NEPDEV-7621
	 * @return void
	 */
	public function testNEPDEV_7621(): void {
		
		$user = $this->userRepository->selectUserByUserId(8926);
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		$activityDay = $activityCustomPeriod->getActivityDay('2013-08-08');
	
		# The original test is no longer valid - see below, in the new system the activity is set to not earn overtime which means the period of 2-6pm is unpaid, which means you don't get work rise for that period either
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number'=>1]));
		
		# The original test is no longer valid: Check the work rise is earned correctly during the overtime, the original bug was the overtime was overlapping the basic pay part of the day by 1 hour so 1 hour was skipped from the work rise
		#$this->assertEquals((4 * 3600), $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, array('number'=>1)));
	}
	
	/**
	 * covers SalaryConceptWorkRises::process
	 * @link https://gemini.nepton.com/workspace/0/item/2980
	 * @return void
	 */
	public function testNEPDEV_2980(): void {
		$user = $this->userRepository->selectUserByUserId(17997);
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		// Earn work rise from assumed lunch = N
		$activityDay = $activityCustomPeriod->getActivityDay('2013-01-28');
		$this->assertEquals(4.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));

		// Earn work rise from assumed lunch = Y
		$activityDay = $activityCustomPeriod->getActivityDay('2013-01-29');
		$this->assertEquals(5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));

		$activityDay = $activityCustomPeriod->getActivityDay('2013-01-30');
		$this->assertEquals(2 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 2]));
	}
	
	/**
	 * covers SalaryConceptWorkRises::process
	 * @link https://gemini.nepton.com/workspace/0/item/8359
	 * @return void
	 */
	public function testNEPDEV_8359(): void {
		$user = $this->userRepository->selectUserByUserId(17997);
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		$activityDay = $activityCustomPeriod->getActivityDay('2013-05-27');
		$this->assertEquals(6 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
	}
	
	/**
	 * Tests that if training is targeted to inmade, it doesn't earn work rise that is not earned from training and earned from targeted to inmade.
	 * covers SalaryConceptWorkRises::process
	 * @link https://gemini.nepton.com/workspace/0/item/8634
	 * @return void
	 */
	public function testNEPDEV_8634(): void {
		$user = $this->userRepository->selectUserByUserId(17997);
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		$activityDay = $activityCustomPeriod->getActivityDay('2013-08-05');
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 2]));
	}
	
	/**
	 * covers SalaryConceptWorkRises::process
	 * @link https://gemini.nepton.com/workspace/0/item/10048
	 * @return void
	 */
	public function test_targetedToInmade(): void {
		$user = $this->userRepository->selectUserByUserId(17997);
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		$activityDay = $activityCustomPeriod->getActivityDay('2013-11-11');
		$this->assertEquals(1.75 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 2]));
	}

	/**
	 * covers SalaryConceptWorkRises::process
	 * @link https://gemini.nepton.com/workspace/0/item/12347
	 * @return void
	 */
	public function testWorkRiseNotEarnedAfterThreshold(): void {
		// Aamutuntikorvaus earned from 00 - 06 but they only want it earned if the user starts work before 05:40. User starts at 05:43 so nothing should be earned
		$user = $this->userRepository->selectUserByUserId(10830);
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		$activityDay = $activityCustomPeriod->getActivityDay('2015-02-03');
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));

		// This user starts work at 5:28 so should earn the work rise
		$user = $this->userRepository->selectUserByUserId(9896); // Vesa Koivisto, Nokian Panimo
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		$activityDay = $activityCustomPeriod->getActivityDay('2015-01-05');
		$this->assertEquals(32 * 60, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
	}

	/**
	 * This is a regression test as at the time of writing we dont have a way of configuring activity types to earn/not earn work rise. So this is simply
	 * here to make sure when we add that functionality that we dont go and break this for them again
	 * covers SalaryConceptWorkRises::process
	 * @link https://gemini.nepton.com/workspace/0/item/12628
	 * @return void
	 */
	public function testWorkRiseNotEarnedFromBankLeave(): void {
		$user = $this->userRepository->selectUserByUserId(4121); // Salla Pyykkönen, KVTL/KVPS
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		$activityDay = $activityCustomPeriod->getActivityDay('2015-06-23');
		// Its a bank leave day so zero work rises should be earned
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class));
	}

	/**
	 * covers SalaryConceptWorkRises::process
	 * @link https://gemini.nepton.com/workspace/0/item/11791
	 * @return void
	 */
	public function testTimeDependantWorkRiseMinuteIncrease(): void {
		$user = $this->userRepository->selectUserByUserId(17997); // 7552 Unittest - UT_Vanilla

		$settingValueProxy = SettingValueProxy::getInstance();
		$settingValueProxy->fixSettingValue($user->getId(), 'TimeDependantWorkRiseIncreaseMethod', 'minutes');
		$settingValueProxy->fixSettingValue($user->getId(), 'TimeDependantWorkRiseMinuteIncrease', 10);
		$settingValueProxy->fixSettingValue($user->getId(), 'workRiseAccrualTarget', ActivityPayTarget::SALARY);
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user, false, null, $settingValueProxy);
		$activityDay = $activityCustomPeriod->getActivityDay('2013-01-28');

		/** @var TimeSpanWorkRiseActive[] $spans */
		$spans = $activityDay->getTimeSpanCollection()->getTimeSpansByClassType(TimeSpanWorkRiseActive::class, ['number' => 1]);
		$this->assertCount(2, $spans);
		$this->assertEquals(10 * 100 / 60, $spans[0]->workRisePercentage);
		$this->assertEquals(10 * 100 / 60, $spans[1]->workRisePercentage);
	}

	/**
	 * covers SalaryConceptWorkRises::process
	 * @link https://gemini.nepton.com/workspace/0/item/11791
	 * @return void
	 */
	public function testTimeDependantWorkRiseIncreaseMethod(): void {
		$user = $this->userRepository->selectUserByUserId(17997); // 7552 Unittest - UT_Vanilla

		$settingValueProxy = SettingValueProxy::getInstance();
		$settingValueProxy->fixSettingValue($user->getId(), 'TimeDependantWorkRiseIncreaseMethod', 'percentage');
		$settingValueProxy->fixSettingValue($user->getId(), 'TimeDependantWorkRisePercentage', 10);
		$settingValueProxy->fixSettingValue($user->getId(), 'TimeDependantWorkRiseMinuteIncrease', 10);
		$settingValueProxy->fixSettingValue($user->getId(), 'workRiseAccrualTarget', ActivityPayTarget::SALARY);
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user, false, null, $settingValueProxy);
		$activityDay = $activityCustomPeriod->getActivityDay('2013-01-28');

		/** @var TimeSpanWorkRiseActive[] $spans */
		$spans = $activityDay->getTimeSpanCollection()->getTimeSpansByClassType(TimeSpanWorkRiseActive::class, ['number' => 1]);
		$this->assertCount(2, $spans);
		$this->assertEquals(10, $spans[0]->workRisePercentage);
		$this->assertEquals(10, $spans[1]->workRisePercentage);
	}

	/**
	 * Tests Epilepsialiitto work rise hack.
	 * covers SalaryConceptWorkRises::process
	 * @link https://gemini.nepton.com/workspace/0/item/13483
	 * @return void
	 */
	public function testEpilepsialiittoHack(): void {
		global $databaseConnection;
		$customerId = $databaseConnection->newQuery('common_customer', 'c')->select('c', 'id')->condition($databaseConnection->newCondition()
			->conditionValue('c', 'id', CUSTOMER_EPILEPSIALIITTO_ID))->execute()->fetchColumn();

		if (!empty($customerId)) {
			$testUser = new User();
			$testUser->setId(1);
			$testUser->setCustomerId(CUSTOMER_EPILEPSIALIITTO_ID);
			$testUser->setSalaryCalculationsStartDate('2015-10-01');
			$testUser->addUser2UserSettingGroupHistoryRow(3408, null, null); // Setting group that has work hour balance enabled

			$settingValueProxy = SettingValueProxy::getInstance();
			$settingValueProxy->fixSettingValue($testUser->getId(), 'TimeDependantWorkRiseName', '');
			$settingValueProxy->fixSettingValue($testUser->getId(), 'TimeDependantWorkRiseName', '', 2);
			$settingValueProxy->fixSettingValue($testUser->getId(), 'TimeDependantWorkRiseName', '', 3);
			$settingValueProxy->fixSettingValue($testUser->getId(), 'TimeDependantWorkRiseName', '', 4);
			$settingValueProxy->fixSettingValue($testUser->getId(), 'TimeDependantWorkRiseName', '', 5);
			$settingValueProxy->fixSettingValue($testUser->getId(), 'TimeDependantWorkRiseName', '', 6);
			$settingValueProxy->fixSettingValue($testUser->getId(), 'TimeDependantWorkRiseName', '', 7);
			$settingValueProxy->fixSettingValue($testUser->getId(), 'TimeDependantWorkRiseName', 'TestRise', 8);
			$settingValueProxy->fixSettingValue($testUser->getId(), 'TimeDependantWorkRiseIncreaseMethod', 'percent', 8);
			$settingValueProxy->fixSettingValue($testUser->getId(), 'TimeDependantWorkRisePercentage', -50, 8);
			$settingValueProxy->fixSettingValue($testUser->getId(), 'workRiseAccrualTarget', ActivityPayTarget::BALANCE, 8);
			$settingValueProxy->fixSettingValue($testUser->getId(), 'TimeDependantWorkRiseVisibleInWeekTables', 'Y', 8);
			$settingValueProxy->fixSettingValue($testUser->getId(), 'TimeDependantWorkRiseBeginTimeOneInMinutesForMonday', 0, 8);
			$settingValueProxy->fixSettingValue($testUser->getId(), 'TimeDependantWorkRiseEndTimeOneInMinutesForMonday', 420, 8);
			$settingValueProxy->fixSettingValue($testUser->getId(), 'TimeDependantWorkRiseBeginTimeTwoInMinutesForMonday', 1020, 8);
			$settingValueProxy->fixSettingValue($testUser->getId(), 'TimeDependantWorkRiseEndTimeTwoInMinutesForMonday', 1440, 8);
			$settingValueProxy->fixSettingValue($testUser->getId(), 'TimeDependantWorkRiseBeginTimeOneInMinutesForTuesday', 0, 8);
			$settingValueProxy->fixSettingValue($testUser->getId(), 'TimeDependantWorkRiseEndTimeOneInMinutesForTuesday', 420, 8);
			$settingValueProxy->fixSettingValue($testUser->getId(), 'TimeDependantWorkRiseBeginTimeTwoInMinutesForTuesday', 1020, 8);
			$settingValueProxy->fixSettingValue($testUser->getId(), 'TimeDependantWorkRiseEndTimeTwoInMinutesForTuesday', 1440, 8);

			$activityCustomPeriod = new ActivityCustomPeriod(new TimeSpan('2015-10-05 00:00:00', '2015-10-06 23:59:59'), $testUser, false, null, $settingValueProxy);
			// Make sure no activity day caches are saved to database
			$activityCustomPeriod->getAccrualCacheRepositoryProxy()->getAccrualCacheRepository()->setReadOnlyMode(true);

			$activities = [];

			$travelWorkActivity = new Activity(126);
			$travelWorkActivity->setId(1);
			$travelWorkActivity->setBeginDateTime('2015-10-05 06:00:00');
			$travelWorkActivity->setEndDateTime('2015-10-05 15:30:00');
			$travelWorkActivity->setOvertimePayTargets(ActivityPayTarget::BALANCE, ActivityPayTarget::UNCOMPENSATED);
			$travelWorkActivity->setUser($testUser);
			$activities[] = $travelWorkActivity;

			$workActivity = new Activity(ACTIVITY_TYPE_WORK_ID);
			$workActivity->setId(2);
			$workActivity->setBeginDateTime('2015-10-05 15:30:00');
			$workActivity->setEndDateTime('2015-10-05 19:00:00');
			$workActivity->setOvertimePayTargets(ActivityPayTarget::BALANCE, ActivityPayTarget::UNCOMPENSATED);
			$workActivity->setUser($testUser);
			$activities[] = $workActivity;

			$travelWorkActivity2 = new Activity(126);
			$travelWorkActivity2->setId(3);
			$travelWorkActivity2->setBeginDateTime('2015-10-06 12:00:00');
			$travelWorkActivity2->setEndDateTime('2015-10-06 20:00:00');
			$travelWorkActivity2->setOvertimePayTargets(ActivityPayTarget::BALANCE, ActivityPayTarget::UNCOMPENSATED);
			$travelWorkActivity2->setUser($testUser);
			$activities[] = $travelWorkActivity2;

			$activityCustomPeriod->setActivities($activities);
			$timeSpanCollection = $activityCustomPeriod->getTimeSpanCollectionCollection();

			/** @var TimeSpanWorkRise[] $workRises */
			$workRises = $timeSpanCollection->getTimeSpansByClassType(TimeSpanWorkRise::class);
			$this->assertCount(2, $workRises);
			$this->assertEquals(3600, $workRises[0]->getDurationInSeconds());
			$this->assertEquals(8, $workRises[0]->number);
			$this->assertEquals('2015-10-05 06:00:00', $workRises[0]->getBeginDateTime());
			$this->assertEquals(3 * 3600, $workRises[1]->getDurationInSeconds());
			$this->assertEquals(8, $workRises[1]->number);
			$this->assertEquals('2015-10-06 17:00:00', $workRises[1]->getBeginDateTime());

			/** @var TimeSpanWorkRiseNegativePercentagePartByRiseNumber[] $balanceChanges */
			$balanceChanges = $timeSpanCollection->getTimeSpansByClassType(TimeSpanWorkRiseNegativePercentagePartByRiseNumber::class);
			$this->assertCount(2, $balanceChanges);
			$this->assertEquals(8, $balanceChanges[0]->number);
			$this->assertEquals(-0.5 * 3600, $balanceChanges[0]->value);
			$this->assertEquals(8, $balanceChanges[1]->number);
			$this->assertEquals(-1.5 * 3600, $balanceChanges[1]->value);

			/** @var TimeSpanAccrual[] $balanceChanges */
			$balanceChanges = $timeSpanCollection->getTimeSpansByClassType(TimeSpanAccrual::class, ['accrualInternalName'=> ActivityPayTarget::BALANCE]);
			$this->assertCount(2, $balanceChanges);
			$this->assertEquals(4.5 * 3600, $balanceChanges[0]->changeAmount);
			$this->assertEquals(-1.5 * 3600, $balanceChanges[1]->changeAmount);
		} else {
			$this->markTestSkipped('Epilepsialiitto customer not found');
		}
	}

	/**
	 * Tests work rise is earned only on days with planned work
	 *
	 * covers SalaryConceptWorkRises::process
	 * @link https://gemini.nepton.com/workspace/0/item/9014
	 * @return void
	 */
	public function testWorkRiseFromYearLeaveOnlyOnDaysWithPlannedWork(): void {
		global $databaseConnection;
		$customerId = $databaseConnection->newQuery('common_customer', 'c')->select('c', 'id')->condition($databaseConnection->newCondition()
			->conditionValue('c', 'id', CUSTOMER_LINKOSUO_ID))->execute()->fetchColumn();

		if (!empty($customerId)) {
			$testUser = new User();
			$testUser->setId(1);
			$testUser->setCustomerId(CUSTOMER_LINKOSUO_ID);
			$testUser->setSalaryCalculationsStartDate('2015-12-01');
			$testUser->addUser2UserSettingGroupHistoryRow(2217, null, null);

			$activityCustomPeriod = new ActivityCustomPeriod(new TimeSpan('2015-12-01 00:00:00', '2015-12-02 23:59:59'), $testUser, false, null, null);
			// Make sure no activity day caches are saved to database
			$activityCustomPeriod->getAccrualCacheRepositoryProxy()->getAccrualCacheRepository()->setReadOnlyMode(true);
			$activities = [];

			$workShift = new Activity(ACTIVITY_TYPE_PLANNED_WORK);
			$workShift->setId(1);
			$workShift->setUser($testUser);
			$workShift->setBeginDateTime('2015-12-01 20:00:00');
			$workShift->setEndDateTime('2015-12-02 04:00:00');
			$activities[] = $workShift;

			$yearLeave = new Activity(ACTIVITY_TYPE_YEAR_LEAVE_ID);
			$yearLeave->setId(2);
			$yearLeave->setUser($testUser);
			$yearLeave->setBeginDateTime('2015-12-01 00:00:00');
			$yearLeave->setEndDateTime('2015-12-03 00:00:00');
			$activities[] = $yearLeave;

			$activityCustomPeriod->setActivities($activities);
			$timeSpanCollection = $activityCustomPeriod->getTimeSpanCollectionCollection();
			/** @var TimeSpanWorkRise[] $yearLeaveWorkRiseSpans */
			$yearLeaveWorkRiseSpans = $timeSpanCollection->getTimeSpansByClassType(TimeSpanWorkRise::class, ['originatedFromArray' => [ACTIVITY_TYPE_YEAR_LEAVE_ID]]);

			$this->assertCount(3, $yearLeaveWorkRiseSpans);

			$this->assertEquals(6, $yearLeaveWorkRiseSpans[0]->number);
			$this->assertEquals('2015-12-01 20:00:00', $yearLeaveWorkRiseSpans[0]->getBeginDateTime());
			$this->assertEquals('2015-12-01 21:00:00', $yearLeaveWorkRiseSpans[0]->getEndDateTime());

			$this->assertEquals(7, $yearLeaveWorkRiseSpans[1]->number);
			$this->assertEquals('2015-12-01 21:00:00', $yearLeaveWorkRiseSpans[1]->getBeginDateTime());
			$this->assertEquals('2015-12-02 00:00:00', $yearLeaveWorkRiseSpans[1]->getEndDateTime());

			$this->assertEquals(7, $yearLeaveWorkRiseSpans[2]->number);
			$this->assertEquals('2015-12-02 00:00:00', $yearLeaveWorkRiseSpans[2]->getBeginDateTime());
			$this->assertEquals('2015-12-02 04:00:00', $yearLeaveWorkRiseSpans[2]->getEndDateTime());
		} else {
			$this->markTestSkipped('Linkosuo customer not found');
		}
	}

	/**
	 * Tests Linkosuo work rise from sick leave hack.
	 * covers SalaryConceptWorkRises::process
	 * @link https://gemini.nepton.com/workspace/0/item/9014
	 * @return void
	 */
	public function testLinkosuoWorkRiseFromSickLeaveHack(): void {
		global $databaseConnection;
		$customerId = $databaseConnection->newQuery('common_customer', 'c')->select('c', 'id')->condition($databaseConnection->newCondition()
			->conditionValue('c', 'id', CUSTOMER_LINKOSUO_ID))->execute()->fetchColumn();

		if (!empty($customerId)) {
			$testUser = new User();
			$testUser->setId(1);
			$testUser->setCustomerId(CUSTOMER_LINKOSUO_ID);
			$testUser->setSalaryCalculationsStartDate('2015-12-01');
			$testUser->addUser2UserSettingGroupHistoryRow(2217, null, null);

			$activityCustomPeriod = new ActivityCustomPeriod(new TimeSpan('2015-12-01 00:00:00', '2015-12-02 23:59:59'), $testUser, false, null, null);
			// Make sure no activity day caches are saved to database
			$activityCustomPeriod->getAccrualCacheRepositoryProxy()->getAccrualCacheRepository()->setReadOnlyMode(true);
			$activities = [];

			$sickLeave = new Activity(ACTIVITY_TYPE_SICK_LEAVE_ID);
			$sickLeave->setId(1);
			$sickLeave->setUser($testUser);
			$sickLeave->setBeginDateTime('2015-12-01 20:00:00');
			$sickLeave->setEndDateTime('2015-12-02 04:00:00');
			$activities[] = $sickLeave;

			$activityCustomPeriod->setActivities($activities);
			$timeSpanCollection = $activityCustomPeriod->getTimeSpanCollectionCollection();
			/** @var TimeSpanWorkRise[] $sickLeaveWorkRiseSpans */
			$sickLeaveWorkRiseSpans = $timeSpanCollection->getTimeSpansByClassType(TimeSpanWorkRise::class, ['originatedFromArray' => [ORIGINATED_SICK_LEAVE_TYPE]]);

			$this->assertCount(3, $sickLeaveWorkRiseSpans);

			$this->assertEquals(4, $sickLeaveWorkRiseSpans[0]->number);
			$this->assertEquals('2015-12-01 20:00:00', $sickLeaveWorkRiseSpans[0]->getBeginDateTime());
			$this->assertEquals('2015-12-01 21:00:00', $sickLeaveWorkRiseSpans[0]->getEndDateTime());

			$this->assertEquals(5, $sickLeaveWorkRiseSpans[1]->number);
			$this->assertEquals('2015-12-01 21:00:00', $sickLeaveWorkRiseSpans[1]->getBeginDateTime());
			$this->assertEquals('2015-12-02 00:00:00', $sickLeaveWorkRiseSpans[1]->getEndDateTime());

			$this->assertEquals(5, $sickLeaveWorkRiseSpans[2]->number);
			$this->assertEquals('2015-12-02 00:00:00', $sickLeaveWorkRiseSpans[2]->getBeginDateTime());
			$this->assertEquals('2015-12-02 04:00:00', $sickLeaveWorkRiseSpans[2]->getEndDateTime());
		}
	}

	/**
	 * Bug regression test that checks work rise is earned correctly overnight from time that falls outside of expected working times.
	 * covers SalaryConceptWorkRises::process
	 * @return void
	 */
	public function testWorkRisesEarnedAroundExpectedWorkingTimes(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER workrise overnight with expectedwork');
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		$activityDay = $activityCustomPeriod->getActivityDay('2016-01-11');
		// This is configured to work like a Corenso use case (no additional/50% overtime) so all 6h should be overtime, and all 6h of overtime should earn the work rise
		$this->assertEquals(6 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number'=>1, 'originatedFromArray' => ORIGINATED_OVERTIME_DAILY_100_PERCENT]));
	}

	/**
	 * covers SalaryConceptWorkRises::process
	 * @return void
	 */
	public function testWorkRiseMinAmountCompensated_NoChange(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER workrise overnight with expectedwork');
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		$activityCustomPeriod->getSettingValueProxy()->fixSettingValue($user->getId(), 'workRiseMinAmountCompensated', 5 * 60);
		$activityDay = $activityCustomPeriod->getActivityDay('2016-01-11');
		$this->assertEquals(6 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number'=>1, 'originatedFromArray' => ORIGINATED_OVERTIME_DAILY_100_PERCENT]));
	}

	/**
	 * covers SalaryConceptWorkRises::process
	 * @return void
	 */
	public function testWorkRiseMinAmountCompensated_TimeIncreased(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER workrise overnight with expectedwork');
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		$activityCustomPeriod->getSettingValueProxy()->fixSettingValue($user->getId(), 'workRiseMinAmountCompensated', 6.5 * 60);
		$activityDay = $activityCustomPeriod->getActivityDay('2016-01-11');
		$this->assertEquals(6.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number'=>1, 'originatedFromArray' => ORIGINATED_OVERTIME_DAILY_100_PERCENT]));
	}

	/**
	 * covers SalaryConceptWorkRises::process
	 * @return void
	 */
	public function testWorkRiseMaxAmountCompensated_NoChange(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER workrise overnight with expectedwork');
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		$activityCustomPeriod->getSettingValueProxy()->fixSettingValue($user->getId(), 'workRiseMaxAmountCompensated', 6.5 * 60);
		$activityDay = $activityCustomPeriod->getActivityDay('2016-01-11');
		$this->assertEquals(6 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number'=>1, 'originatedFromArray' => ORIGINATED_OVERTIME_DAILY_100_PERCENT]));
	}

	/**
	 * covers SalaryConceptWorkRises::process
	 * @return void
	 */
	public function testWorkRiseMaxAmountCompensated_TimeLimited(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER workrise overnight with expectedwork');
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		$activityCustomPeriod->getSettingValueProxy()->fixSettingValue($user->getId(), 'workRiseMaxAmountCompensated', 1 * 60);
		$activityDay = $activityCustomPeriod->getActivityDay('2016-01-11');
		$this->assertEquals(1 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number'=>1, 'originatedFromArray' => ORIGINATED_OVERTIME_DAILY_100_PERCENT]));
	}

	/**
	 * covers SalaryConceptWorkRises::process
	 * @link https://gemini.nepton.com/workspace/0/item/13801
	 * @return void
	 */
	public function testWorkRisesEarnedFromSunday50And100(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER WorkRisesFromSunday50And100Overtime');
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);

		$activityDay = $activityCustomPeriod->getActivityDay('2016-02-07');
		$timeSpanCollection = $activityDay->getTimeSpanCollection();
		$this->assertEquals(2 * 3600, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1, 'overtimeRisePercentage' => 50]));
		$this->assertEquals(5.5 * 3600, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1, 'overtimeRisePercentage' => 100]));
	}

	/**
	 * Bug caused by the bank holiday and the overnight shift making Monday act like a bank holiday resulting in Sunday rise being earned on the Monday
	 * TimeDependantWorkRiseIsIncreasedByWorkDayLengthIfCommonHoliday must be on for the effects of the bug to appear
	 *
	 * @link https://gemini.nepton.com/workspace/0/item/14154
	 * @return void
	 */
	public function testWorkRiseOvernightOnCommonHolidayDays(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER bank holiday work rise overnight');
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		// Sunday should earn the Sunday rise ONLY up till midnight (2 hours)
		$timeSpanCollection = $activityCustomPeriod->getActivityDay('2015-05-24')->getTimeSpanCollection();
		$this->assertEquals(2 * 3600, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));

		// Monday should not earn any sunday rise
		$timeSpanCollection = $activityCustomPeriod->getActivityDay('2015-05-25')->getTimeSpanCollection();
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1, 'overtimeRisePercentage' => 100]));
	}

	/**
	 * Different from the previous test, this bug was caused by days that inherit their bank holiday settings from another day on bank holidays.
	 * i.e. the Wednesday was a bank holiday that should have inherited it's work rise from Sunday and the overnight Tuesday - Wednesday shift
	 * wasn't earning the inherited work rise
	 *
	 * @link https://gemini.nepton.com/workspace/0/item/15080
	 * @return void
	 */
	public function testWorkRiseOvernightOnCommonHolidayDays_2(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER BankHolidayWorkRise with overnight shift 15080');
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		// Because 4h of the Tuesday shift overlaps onto the Wednesday (the 6th) 4h of Sunday rise should be earned (as bank holidays are set to inherit Sunday work rise settings)
		$timeSpanCollection = $activityCustomPeriod->getActivityDay('2016-01-05')->getTimeSpanCollection();
		$this->assertEquals(4 * 3600, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		$timeSpanCollection = $activityCustomPeriod->getActivityDay('2016-01-06')->getTimeSpanCollection();
		$this->assertEquals(0 * 3600, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));

		// Normal Sunday rise on Sunday
		$timeSpanCollection = $activityCustomPeriod->getActivityDay('2016-01-10')->getTimeSpanCollection();
		$this->assertEquals(7.5 * 3600, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
	}

	/**
	 * Test that when you set a work rise setting to be "23:00" -> "05:00" that it actually works overnight
	 * @return void
	 */
	public function testWorkRiseActiveOvernightSettings_ShiftStartRendering(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Overnight workrise setting shift start renderin');
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);

		$activityDay = $activityCustomPeriod->getActivityDay('2016-02-29');
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		$activityDay = $activityCustomPeriod->getActivityDay('2016-03-01');
		$this->assertEquals(6 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		$activityDay = $activityCustomPeriod->getActivityDay('2016-03-02');
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
	}

	/**
	 * Test that when you set a work rise setting to be "06:00" -> "06:00" that works overnight to 6AM on the next day
	 * @return void
	 */
	public function testWorkRiseActiveOvernightSettings_24Hours(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER 24h overnight workrises');
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);

		$activityDay = $activityCustomPeriod->getActivityDay('2016-01-09');
		// Work rise should be earned from 6AM on the Saturday to 6AM on the Sunday as the setting is 06->06.
		$this->assertEquals(8 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
	}

	/**
	 * Tests that the setting works properly even with administrative events in the morning
	 * @link https://gemini.nepton.com/workspace/0/item/15201
	 * @return void
	 */
	public function testWorkRiseNotActiveIfWorkStartsBefore_balanceChangeEvent(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER workRiseNotActiveIfWorkStartsBeforeOrAfter');
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);

		$activityDay = $activityCustomPeriod->getActivityDay('2016-05-24');
		$this->assertEquals(7 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRiseActive::class, ['number' => 2]));
		$this->assertEquals(6.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 2]));
	}

	/**
	 * Tests that the setting works properly even with administrative events in the morning
	 * @link https://gemini.nepton.com/workspace/0/item/15201
	 * @return void
	 */
	public function testWorkRiseNotActiveIfWorkStartsAfter_balanceChangeEvent(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER workRiseNotActiveIfWorkStartsBeforeOrAfter');
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);

		$activityDay = $activityCustomPeriod->getActivityDay('2016-05-23');
		$this->assertEquals(7 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRiseActive::class, ['number' => 1]));
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
	}

	/**
	 * Tests rounding with rounding method normal
	 * @link https://dev.azure.com/neptongroup/Nepton%20Dev/_workitems/edit/6244
	 * @return void
	 */
	public function testWorkRiseRounding_roundingNormal(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Work Rise Rounding');
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);

		$timeSpanCollection = $activityCustomPeriod->getActivityDay('2019-01-09')->getTimeSpanCollection();
		$this->assertEquals(1800, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 3]));
		$this->assertEquals(40 * 60, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanPaidTime::class));

		$timeSpanCollection = $activityCustomPeriod->getActivityDay('2019-01-10')->getTimeSpanCollection();
		$this->assertEquals(3600, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 3]));
		$this->assertEquals(45 * 60, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanPaidTime::class));
	}

	/**
	 * Tests rounding with rounding method down
	 * @link https://dev.azure.com/neptongroup/Nepton%20Dev/_workitems/edit/6244
	 * @return void
	 */
	public function testWorkRiseRounding_roundingDown(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Work Rise Rounding');
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);

		$timeSpanCollection = $activityCustomPeriod->getActivityDay('2019-01-08')->getTimeSpanCollection();
		$this->assertEquals(1800, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 2]));
		$this->assertEquals(58 * 60, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanPaidTime::class));
	}

	/**
	 * Tests rounding with rounding method up
	 * @link https://dev.azure.com/neptongroup/Nepton%20Dev/_workitems/edit/6244
	 * @return void
	 */
	public function testWorkRiseRounding_roundingUp(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Work Rise Rounding');
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);

		$timeSpanCollection = $activityCustomPeriod->getActivityDay('2019-01-07')->getTimeSpanCollection();
		$this->assertEquals(1800, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		$this->assertEquals(5 * 60, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanPaidTime::class));
	}

	/**
	 * @todo implement
	 * @return void
	 */
	public function testWorkRiseRounding_roundingThreshold(): void {
		$this->markTestIncomplete('TODO');
	}

	/**
	 * @link https://gemini.nepton.com/workspace/0/item/14274
	 * @return void
	 */
	public function testWorkRise_workRiseIncludesEmergencyWorkSetting(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Emergency work');
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);

		$riseWhereOnlyTimeFromEmergencyWorkIsEarned = 1; // "NIGHT EMERGENCY WORK RISE" 21:00 -> 24:00
		$riseWhereEmergencyWorkIsExcluded = 3; // "24h work rise NOT from emergency work" 00:00 -> 24:00

		$activityDay = $activityCustomPeriod->getActivityDay('2015-01-15');
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => $riseWhereOnlyTimeFromEmergencyWorkIsEarned]));
		$this->assertEquals(7.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => $riseWhereEmergencyWorkIsExcluded]));

		$activityDay = $activityCustomPeriod->getActivityDay('2015-01-16');
		$this->assertEquals(4 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => $riseWhereOnlyTimeFromEmergencyWorkIsEarned]));
		$this->assertEquals(7.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => $riseWhereEmergencyWorkIsExcluded]));

		$activityDay = $activityCustomPeriod->getActivityDay('2015-01-17');
		$this->assertEquals(0 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => $riseWhereOnlyTimeFromEmergencyWorkIsEarned]));
		$this->assertEquals(1 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => $riseWhereEmergencyWorkIsExcluded]));
	}

	/**
	 * @link https://gemini.nepton.com/workspace/0/item/18081
	 * @link https://gemini.nepton.com/workspace/0/item/18042
	 * @link https://dev.azure.com/neptongroup/Nepton%20Dev/_workitems/edit/888
	 * @return void
	 */
	public function testEmergencyWorkDayAndNightCompensationCanBeEarnedExclusively(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Emergency work day and night');
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);

		$dayEmergencyWorkCompensation = 1; // Day Emergency (6-21)
		$nightEmergencyWorkCompensation = 2; // Day Emergency (21-6)

		// Test notes: Day emergency compensation is configured to be earned for any emergency work events that BEGIN between 6:00 and 21:00 (end time not inclusive) and pay 2h fixed compensation only ONCE on the day (no matter how many daily emergency work events there are)

		// Monday to Wednesday all have a single emergency work event that BEGINS between the 6 - 21 day compensation window so 2h should be earned. ZERO night emergency work compensation should be earned even though the time overlaps the night period
		$activityDay = $activityCustomPeriod->getActivityDay('2017-09-25');
		$this->assertEquals(2 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => $dayEmergencyWorkCompensation]));
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => $nightEmergencyWorkCompensation]));
		$activityDay = $activityCustomPeriod->getActivityDay('2017-09-26');
		$this->assertEquals(2 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => $dayEmergencyWorkCompensation]));
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => $nightEmergencyWorkCompensation]));
		$activityDay = $activityCustomPeriod->getActivityDay('2017-09-27');
		$this->assertEquals(2 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => $dayEmergencyWorkCompensation]));
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => $nightEmergencyWorkCompensation]));

		// Thursday and Friday both have a single emergency work event that BEGINS between the 0-6 and 21-24 (21-6) nighttime compensation window so a fixed 4h should be earned. ZERO day emergency work compensation should be earned even though the time overlaps the day
		$activityDay = $activityCustomPeriod->getActivityDay('2017-09-28');
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => $dayEmergencyWorkCompensation]));
		$this->assertEquals(4 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => $nightEmergencyWorkCompensation]));
		$activityDay = $activityCustomPeriod->getActivityDay('2017-09-29');
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => $dayEmergencyWorkCompensation]));
		$this->assertEquals(4 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => $nightEmergencyWorkCompensation]));

		// On Saturday there are 2 night emergency callouts, and workRiseFixedCompensationPayMethod is configured to compensate BOTH so 4h x 2 = 8h
		$activityDay = $activityCustomPeriod->getActivityDay('2017-09-30');
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => $dayEmergencyWorkCompensation]));
		$this->assertEquals(8 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => $nightEmergencyWorkCompensation]));

		// On Sunday there are 2 day emergency callouts, and workRiseFixedCompensationPayMethod is configured to compensate ONLY ONCE so 2h
		$activityDay = $activityCustomPeriod->getActivityDay('2017-10-01');
		$this->assertEquals(2 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => $dayEmergencyWorkCompensation]));
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => $nightEmergencyWorkCompensation]));

		// On Monday both a single night and day emergency work callout were created, so compensate both
		$activityDay = $activityCustomPeriod->getActivityDay('2017-10-02');
		$this->assertEquals(2 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => $dayEmergencyWorkCompensation]));
		$this->assertEquals(4 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => $nightEmergencyWorkCompensation]));
	}

	/**
	 * @link https://gemini.nepton.com/workspace/0/item/14274
	 * @return void
	 */
	public function testWorkRise_workRiseForceAdditionalWorkOfUnderlyingShiftSetting(): void {
		$this->markTestIncomplete();
	}

	/**
	 * @link https://gemini.nepton.com/workspace/0/item/14274
	 * @return void
	 */
	public function testWorkRise_workRiseForceOvertimeOfUnderlyingShiftSetting(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Emergency work');
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);

		$workRiseIndex = 1; // "NIGHT EMERGENCY WORK RISE" 21:00 -> 24:00 (index 1)

		// 4.25h of emergency work, with the last 4h forced to 100% by the night emergency work rise. The first 15 minutes is overtime 50% under the max day length so is TES overtime
		$activityDay = $activityCustomPeriod->getActivityDay('2015-01-16');
		$this->assertEquals(15 * 60, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanOvertimeDaily50::class));
		$this->assertEquals(15 * 60, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanOvertimeDaily50::class, ['isTesOvertime'=>true]));
		// The last 4h which has been forced to 100% by the rise has 15 mins TES overtime up to the max day length and then the rest is normal overtime over the max day length
		$this->assertEquals(0.25 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanOvertimeDaily100::class, ['isTesOvertime'=>true, 'originatedFromArray' => ORIGINATED_NON_ADDITIONAL_OVERTIME_FORCED_TO_SALARY_TYPE_BY_WORK_RISE]));
		$this->assertEquals(3.75 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanOvertimeDaily100::class, ['isTesOvertime'=>false]));

		// Try again but force the time to 50% instead of 100%
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		$activityCustomPeriod->getSettingValueProxy()->fixSettingValue($user->getId(), 'workRiseForceOvertimeOfUnderlyingShift', 50, $workRiseIndex);
		$activityDay = $activityCustomPeriod->getActivityDay('2015-01-16');
		$this->assertEquals(4.25 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanOvertimeDaily50::class));
		$this->assertEquals(30 * 60, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanOvertimeDaily50::class, ['isTesOvertime'=>true]));

		// From the 4.25 hours of overtime the first 2h are always going to be 50% (because of emergency work), so only the remaining 2.25 hours are forced
		$this->assertEquals(2.25 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanOvertimeDaily50::class, ['originatedFromArray' => ORIGINATED_NON_ADDITIONAL_OVERTIME_FORCED_TO_SALARY_TYPE_BY_WORK_RISE]));
	}

	/**
	 * @link https://gemini.nepton.com/workspace/0/item/15703
	 * @return void
	 */
	public function testWorkRise_ForceFirstHourOfOvertimeToBeBalanceFlexitime(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Juustoportti overtime use case');
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		$activityDay = $activityCustomPeriod->getActivityDay('2016-09-05');

		// Normally they would earn 0:30 additional, 2:00 of daily 50% and 0:31 of daily 100% but we are forcing the first 1h to base part saldo, incremented part uncompensated
		// so we should end up with 1h of basic time targeted to saldo, and 1:30 of daily 50% and 0:31 of daily 100%
		$this->assertEquals(7.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanBasicTime::class, ['basePartTarget'=>ActivityPayTarget::SALARY]));
		$this->assertEquals(1 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanBasicTime::class, ['basePartTarget'=>ActivityPayTarget::BALANCE, 'overtimeIncrementPartTargetedTo'=>ActivityPayTarget::UNCOMPENSATED]));
		$this->assertEquals(1.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanOvertimeDaily50::class, ['basePartTarget'=>ActivityPayTarget::SALARY, 'overtimeIncrementPartTargetedTo'=>ActivityPayTarget::SALARY]));
		$this->assertEquals(31 * 60, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanOvertimeDaily100::class, ['basePartTarget'=>ActivityPayTarget::SALARY, 'overtimeIncrementPartTargetedTo'=>ActivityPayTarget::SALARY]));
		$this->assertEquals(1 * 3600, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BALANCE));

		// Work rise (NOT SURE WHETHER THE WORK RISE SHOULD BE FORCED AS WELL AS IT'S NOT A REQUIREMENT BY ANY CUSTOMER SO NOT FORCING IT)
		$this->assertEquals(1 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
	}

	/**
	 * Without the work rise this example would earn 1.5h of daily 50% overtime that gets rounded to 2h.
	 * The work rise is configured to force 50% overtime to 100% but the bug was that the rounded half
	 * an hour wasn't forced.
	 * @link https://dev.azure.com/neptongroup/Nepton%20Dev/_workitems/edit/7147
	 * @return void
	 */
	public function testWorkRise_ForceRoundedOvertimeTo100PercentOvertime(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Work rise can force rounded overtime');
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		$activityDay = $activityCustomPeriod->getActivityDay('2019-05-03');
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanOvertimeDaily50::class));
		$this->assertEquals(2 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanOvertimeDaily100::class));
	}

	/**
	 * @link https://gemini.nepton.com/workspace/0/item/15362
	 * @return void
	 */
	public function testWorkRise_workRiseNotFromBasicWorkingHours(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Work rises earned from basic salary work');
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		$activityDay = $activityCustomPeriod->getActivityDay('2016-07-11');

		// Work rise only from overtime
		$this->assertEquals(1.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));

		// Work rise only from basic salary
		$this->assertEquals(8 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 2]));
	}

	/**
	 * @link https://gemini.nepton.com/workspace/0/item/17637
	 * @return void
	 */
	public function testWorkRise_workRiseOnlyFromHourToHourTimeNotBasic(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Work rise earned from h2h but not basic');
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		$activityDay = $activityCustomPeriod->getActivityDay('2017-03-06');

		// Both regular pay and hour to hour both produce TimeSpanBasicTime, this test checks that a work rise can be earned from hour to hour but not the normal working hours
		// To be very similar to a customer use case we are using a custom travel work earning threshold of 10 hours (meaning they can 2.5h hour to hour)
		// but with a 2h earning limit
		$this->assertEquals(2 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));

		// To check it's only earned from the hour to hour part, we need to check the time it starts. The event starts at 8:00 and will earn hour to hour after 8 hours cos of lunch
		$this->assertEquals('2017-03-06 16:00:00', $activityDay->getTimeSpanCollection()->getFirstTimeSpanOfType(TimeSpanWorkRise::class, ['number' => 1])->getBeginDateTime());
	}


	/**
	 * Testing that you can configure to earn work rises for work starting on a previous day providing the shift belongs to current day and not the previous day
	 *
	 * @link https://gemini.nepton.com/workspace/295/item/16665
	 * @return void
	 */
	public function testWorkRise_PreviousDayTimeSettings_RiseEarned(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Previous and next day relative time settings');
		$activityCustomPeriod = new ActivityCustomPeriod(new TimeSpan('2016-11-21', '2016-12-05'), $user);

		// Earned from the period of 3:59 AM - 06:00 AM on current day = 2:01
		$activityDay = $activityCustomPeriod->getActivityDay('2016-11-21');
		$this->assertEquals(121 * 60, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		$workRiseActivePeriod = $activityDay->getTimeSpanCollection()->getFirstTimeSpanOfType(TimeSpanWorkRiseActive::class, ['number'=>1]);
		if ($workRiseActivePeriod instanceof TimeSpanWorkRiseActive) {
			// The active period of the work rise should start on the *previous* day and end on the current
			$this->assertEquals('2016-11-20 22:00:00', $workRiseActivePeriod->getBeginDateTime());
			$this->assertEquals('2016-11-21 06:00:00', $workRiseActivePeriod->getEndDateTime());
		} else {
			$this->fail('Active span not found');
		}

		// Earned from the period of 22:00 (*Sunday*) - 06:00 AM on Monday = 8:00
		$activityDay = $activityCustomPeriod->getActivityDay('2016-12-05');
		$this->assertEquals(8 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		$workRiseActivePeriod = $activityDay->getTimeSpanCollection()->getFirstTimeSpanOfType(TimeSpanWorkRiseActive::class, ['number'=>1]);
		if ($workRiseActivePeriod instanceof TimeSpanWorkRiseActive) {
			// The active period of the work rise should start on the *previous* day and end on the current
			$this->assertEquals('2016-12-04 22:00:00', $workRiseActivePeriod->getBeginDateTime());
			$this->assertEquals('2016-12-05 06:00:00', $workRiseActivePeriod->getEndDateTime());
		} else {
			$this->fail('Active span not found');
		}
	}

	/**
	 * Testing that you can configure to earn work rises for work starting on a previous day providing the shift belongs to current day and not the previous day
	 *
	 * @link https://gemini.nepton.com/workspace/295/item/16665
	 * @return void
	 */
	public function testWorkRise_PreviousDayTimeSettings_RiseNotEarned(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Previous and next day relative time settings');
		$activityCustomPeriod = new ActivityCustomPeriod(new TimeSpan('2016-11-14', '2016-11-28'), $user);

		// The Monday rise is active but not earned because the work starts after 4AM on the Monday and the settings say it should start before 4AM to qualify
		$activityDay = $activityCustomPeriod->getActivityDay('2016-11-14');
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		$workRiseActivePeriod = $activityDay->getTimeSpanCollection()->getFirstTimeSpanOfType(TimeSpanWorkRiseActive::class, ['number'=>1]);
		if ($workRiseActivePeriod instanceof TimeSpanWorkRiseActive) {
			// The active period of the work rise should start on the *previous* day and end on the current
			$this->assertEquals('2016-11-13 22:00:00', $workRiseActivePeriod->getBeginDateTime());
			$this->assertEquals('2016-11-14 06:00:00', $workRiseActivePeriod->getEndDateTime());
		} else {
			$this->fail('Active span not found');
		}

		// This time the work does actually overlap with the Monday rise period (that starts on Sunday at 22:00) but because it's not a Monday shift, it should not be earned! As the settings are configured to apply to Monday shifts only which is why the work rise active span isn't present either
		$activityDay = $activityCustomPeriod->getActivityDay('2016-11-20');
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		$workRiseActivePeriod = $activityDay->getTimeSpanCollection()->getFirstTimeSpanOfType(TimeSpanWorkRiseActive::class, ['number'=>1]);
		$this->assertEmpty($workRiseActivePeriod);

		// Confirm that looking at the Monday you do indeed find the settings that overlap the Monday
		$activityDay = $activityCustomPeriod->getActivityDay('2016-11-21');
		$workRiseActivePeriod = $activityDay->getTimeSpanCollection()->getFirstTimeSpanOfType(TimeSpanWorkRiseActive::class, ['number'=>1]);
		if ($workRiseActivePeriod instanceof TimeSpanWorkRiseActive) {
			// The active period of the work rise should start on the *previous* day and end on the current
			$this->assertEquals('2016-11-20 22:00:00', $workRiseActivePeriod->getBeginDateTime());
			$this->assertEquals('2016-11-21 06:00:00', $workRiseActivePeriod->getEndDateTime());
		} else {
			$this->fail('Active span not found');
		}

		// No Monday rise should be earned on Wednesday
		$activityDay = $activityCustomPeriod->getActivityDay('2016-11-23');
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		$workRiseActivePeriod = $activityDay->getTimeSpanCollection()->getFirstTimeSpanOfType(TimeSpanWorkRiseActive::class, ['number'=>1]);
		$this->assertEmpty($workRiseActivePeriod);

		// No Monday rise should be on Monday 28th because it's configured to ignore shifts that start before 20:00 on the *previous* day and the shift starts at 19:59
		$activityDay = $activityCustomPeriod->getActivityDay('2016-11-28');
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		$workRiseActivePeriod = $activityDay->getTimeSpanCollection()->getFirstTimeSpanOfType(TimeSpanWorkRiseActive::class, ['number'=>1]);
		if ($workRiseActivePeriod instanceof TimeSpanWorkRiseActive) {
			// The active period of the work rise should start on the *previous* day and end on the current
			$this->assertEquals('2016-11-27 22:00:00', $workRiseActivePeriod->getBeginDateTime());
			$this->assertEquals('2016-11-28 06:00:00', $workRiseActivePeriod->getEndDateTime());
		} else {
			$this->fail('Active span not found');
		}
	}

	/**
	 * Testing that you can configure to earn work rises for work starting on a previous day and running to the next day, providing the shift belongs to current day and not the previous or next day
	 *
	 * @link https://gemini.nepton.com/workspace/295/item/16665
	 * @return void
	 */
	public function testWorkRise_NextDayTimeSettings_RiseEarned(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Previous and next day relative time settings');
		$activityCustomPeriod = new ActivityCustomPeriod(new TimeSpan('2016-11-23', '2016-11-23'), $user);

		// Wednesday rise is configured to earn from Tuesday 23:00 -> Thursday 01:00 for Wednesday shifts only
		// The first Wednesday shift earns work rise from Tuesday 23:00 to Wednesday 06:00 = 7 hours
		// The second Wednesday shift earns work rise from Wednesday 22:00 to Thursday 01:00 = 3 hours
		// Note we can only have both overnight shifts appear on Wednesday because we have set the expected work times to have two expected times in the same day
		$activityDay = $activityCustomPeriod->getActivityDay('2016-11-23');
		$this->assertEquals(10 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 2]));
		$workRiseActivePeriod = $activityDay->getTimeSpanCollection()->getFirstTimeSpanOfType(TimeSpanWorkRiseActive::class, ['number'=>2]);
		if ($workRiseActivePeriod instanceof TimeSpanWorkRiseActive) {
			// The active period of the work rise should start on the *previous* day and end on the *next* day
			$this->assertEquals('2016-11-22 23:00:00', $workRiseActivePeriod->getBeginDateTime());
			$this->assertEquals('2016-11-24 01:00:00', $workRiseActivePeriod->getEndDateTime());
		} else {
			$this->fail('Active span not found');
		}
	}

	/**
	 * Tests that when we have work day start time at 7:30 and we are earning Sunday work rise until 7:30 on Monday
	 * that the rise is earned even when there is no work marking on Sunday but a work marking early on Monday.
	 *
	 * https://gemini.nepton.com/workspace/0/item/17791
	 * @return void
	 */
	public function testWorkRise_NextDayTimeSettings_sundayRiseOnMondayNight(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER work rise earned, active & events on next day');
		$activityCustomPeriod = new ActivityCustomPeriod(new TimeSpan('2017-04-23 00:00:00', '2017-04-23 23:59:59'), $user);

		$activityDay = $activityCustomPeriod->getActivityDay('2017-04-23');
		$this->assertEquals((2 * 3600) + (15 * 60), $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		$workRiseActivePeriod = $activityDay->getTimeSpanCollection()->getFirstTimeSpanOfType(TimeSpanWorkRiseActive::class, ['number' => 1]);
		if ($workRiseActivePeriod instanceof TimeSpanWorkRiseActive) {
			// The active period of the work rise should start on the *previous* day and end on the *next* day
			$this->assertEquals('2017-04-23 07:30:00', $workRiseActivePeriod->getBeginDateTime());
			$this->assertEquals('2017-04-24 07:30:00', $workRiseActivePeriod->getEndDateTime());
		} else {
			$this->fail('Active span not found');
		}
	}

	/**
	 * Tests for new settings that allow defining whether work rise is earned from regular pay overtime by overtime
	 * targeting (bank, balance, salary)
	 *
	 * @link https://gemini.nepton.com/workspace/0/item/16231
	 * @return void
	 */
	public function testWorkRise_byOvertimeTargeting(): void {
		$testUser = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Work rise earning from overtime settings');
		$activityCustomPeriod = new ActivityCustomPeriod(new TimeSpan('2016-09-05 00:00:00', '2016-09-08 23:59:59'), $testUser);

		$activityDay = $activityCustomPeriod->getActivityDay('2016-09-05');
		// Work rise from basic time
		$this->assertEquals(7.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		// Work rise from regular pay overtime to balance (aka flexi-time)
		$this->assertEquals(2 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 2]));
		// Work rise from regular pay overtime to bank
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 3]));
		// Work rise from regular pay overtime to salary
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 4]));
		// Overtime
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 5]));

		$activityDay = $activityCustomPeriod->getActivityDay('2016-09-06');
		// Work rise from basic time
		$this->assertEquals(7.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		// Work rise from regular pay overtime to balance (aka flexi-time)
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 2]));
		// Work rise from regular pay overtime to bank
		$this->assertEquals(2 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 3]));
		// Work rise from regular pay overtime to salary
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 4]));
		// Overtime
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 5]));

		$activityDay = $activityCustomPeriod->getActivityDay('2016-09-07');
		// Work rise from basic time
		$this->assertEquals(7.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		// Work rise from regular pay overtime to balance (aka flexi-time)
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 2]));
		// Work rise from regular pay overtime to bank
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 3]));
		// Work rise from regular pay overtime to salary
		$this->assertEquals(2 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 4]));
		// Overtime
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 5]));

		$activityDay = $activityCustomPeriod->getActivityDay('2016-09-08');
		// Work rise from basic time
		$this->assertEquals(7.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		// Work rise from regular pay overtime to balance (aka flexi-time)
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 2]));
		// Work rise from regular pay overtime to bank
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 3]));
		// Work rise from regular pay overtime to salary
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 4]));
		// Overtime
		$this->assertEquals(2 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 5]));
	}

	/**
	 * Tests new settings that allow defining whether work rise is earned from different types of overtime compensation
	 *
	 * @link https://gemini.nepton.com/workspace/0/item/16876
	 * @return void
	 */
	public function testWorkRise_ByOvertimeCompensationType_AllTypes(): void {
		$testUser = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Work rise earning from overtime settings 2');
		$activityCustomPeriod = new ActivityCustomPeriod(new TimeSpan('2017-02-13 00:00:00', '2017-02-19 23:59:59'), $testUser);

		// With the work rise set to earn from all compensation types we should see the following
		$timeSpanCollection = $activityCustomPeriod->getTimeSpanCollectionCollection();

		// 37.5h basic up to week length
		$this->assertEquals(37.5 * 3600, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_BASIC_SALARY_TIME]));

		// 2.5h additional up to max day/week length
		$this->assertEquals(2.5 * 3600, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_ADDITIONAL_WORK]));

		// 8h weekly 50% up to max weekly 50% limit
		$this->assertEquals(8 * 3600, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_WEEKLY_50_PERCENT]));

		// 8h weekly 100% up to max day length
		$this->assertEquals(8 * 3600, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_WEEKLY_100_PERCENT]));

		// 2h daily 50% up to daily 50% limit (Mon - Fri) = 2 * 5 = 10h
		$this->assertEquals(10 * 3600, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_DAILY_50_PERCENT]));

		// 1.5h daily 100% up to end of day (Mon - Fri) + 3.5h on Sat and Sun (because 100% daily is earned after weekly overtime) = 14.5h
		$this->assertEquals(14.5 * 3600, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_DAILY_100_PERCENT]));
	}

	/**
	 * Tests new settings that allow defining whether work rise is earned from different types of overtime compensation
	 *
	 * @link https://gemini.nepton.com/workspace/0/item/16876
	 * @return void
	 */
	public function testWorkRise_ByOvertimeCompensationType_NoCompensationTypes(): void {
		$testUser = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Work rise earning from overtime settings 2');
		$activityCustomPeriod = new ActivityCustomPeriod(new TimeSpan('2017-02-13 00:00:00', '2017-02-19 23:59:59'), $testUser);
		$settingValueProxy = $activityCustomPeriod->getSettingValueProxy();
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromBasicSalaryWork', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromAdditionalWork', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeDaily50', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeDaily100', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeWeekly50', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeWeekly100', 'N');

		// With the work rise set to earn from all compensation types we should see the following
		$timeSpanCollection = $activityCustomPeriod->getTimeSpanCollectionCollection();

		// Work rise from basic salary time
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_BASIC_SALARY_TIME]));

		// Work rise from additional work overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_ADDITIONAL_WORK]));

		// Work rise from weekly 50% overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_WEEKLY_50_PERCENT]));

		// Work rise from weekly 100% overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_WEEKLY_100_PERCENT]));

		// Work rise from daily 50% overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_DAILY_50_PERCENT]));

		// Work rise from daily 100% overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_DAILY_100_PERCENT]));
	}

	/**
	 * Tests new settings that allow defining whether work rise is earned from different types of overtime compensation
	 *
	 * @link https://gemini.nepton.com/workspace/0/item/16876
	 * @return void
	 */
	public function testWorkRise_ByOvertimeCompensationType_OnlyAdditionalWork(): void {
		$testUser = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Work rise earning from overtime settings 2');
		$activityCustomPeriod = new ActivityCustomPeriod(new TimeSpan('2017-02-13 00:00:00', '2017-02-19 23:59:59'), $testUser);
		$settingValueProxy = $activityCustomPeriod->getSettingValueProxy();
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromBasicSalaryWork', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromAdditionalWork', 'Y');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeDaily50', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeDaily100', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeWeekly50', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeWeekly100', 'N');

		// With the work rise set to earn from all compensation types we should see the following
		$timeSpanCollection = $activityCustomPeriod->getTimeSpanCollectionCollection();

		// Work rise from basic salary time
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_BASIC_SALARY_TIME]));

		// Work rise from additional work overtime. 2.5h up to max day/week length
		$this->assertEquals(2.5 * 3600, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_ADDITIONAL_WORK]));

		// Work rise from weekly 50% overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_WEEKLY_50_PERCENT]));

		// Work rise from weekly 100% overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_WEEKLY_100_PERCENT]));

		// Work rise from daily 50% overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_DAILY_50_PERCENT]));

		// Work rise from daily 100% overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_DAILY_100_PERCENT]));
	}

	/**
	 * Tests new settings that allow defining whether work rise is earned from different types of overtime compensation
	 *
	 * @link https://gemini.nepton.com/workspace/0/item/16876
	 * @return void
	 */
	public function testWorkRise_ByOvertimeCompensationType_OnlyOvertimeDaily50(): void {
		$testUser = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Work rise earning from overtime settings 2');
		$activityCustomPeriod = new ActivityCustomPeriod(new TimeSpan('2017-02-13 00:00:00', '2017-02-19 23:59:59'), $testUser);
		$settingValueProxy = $activityCustomPeriod->getSettingValueProxy();
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromBasicSalaryWork', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromAdditionalWork', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeDaily50', 'Y');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeDaily100', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeWeekly50', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeWeekly100', 'N');

		// With the work rise set to earn from all compensation types we should see the following
		$timeSpanCollection = $activityCustomPeriod->getTimeSpanCollectionCollection();

		// Work rise from basic salary time
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_BASIC_SALARY_TIME]));

		// Work rise from additional work overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_ADDITIONAL_WORK]));

		// Work rise from weekly 50% overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_WEEKLY_50_PERCENT]));

		// Work rise from weekly 100% overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_WEEKLY_100_PERCENT]));

		// Work rise from daily 50% overtime (2h for Mon - Fri)
		$this->assertEquals(10 * 3600, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_DAILY_50_PERCENT]));

		// Work rise from daily 100% overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_DAILY_100_PERCENT]));
	}

	/**
	 * Tests new settings that allow defining whether work rise is earned from different types of overtime compensation
	 *
	 * @link https://gemini.nepton.com/workspace/0/item/16876
	 * @return void
	 */
	public function testWorkRise_ByOvertimeCompensationType_OnlyOvertimeDaily100(): void {
		$testUser = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Work rise earning from overtime settings 2');
		$activityCustomPeriod = new ActivityCustomPeriod(new TimeSpan('2017-02-13 00:00:00', '2017-02-19 23:59:59'), $testUser);
		$settingValueProxy = $activityCustomPeriod->getSettingValueProxy();
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromBasicSalaryWork', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromAdditionalWork', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeDaily50', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeDaily100', 'Y');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeWeekly50', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeWeekly100', 'N');

		// With the work rise set to earn from all compensation types we should see the following
		$timeSpanCollection = $activityCustomPeriod->getTimeSpanCollectionCollection();

		// Work rise from basic salary time
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_BASIC_SALARY_TIME]));

		// Work rise from additional work overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_ADDITIONAL_WORK]));

		// Work rise from weekly 50% overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_WEEKLY_50_PERCENT]));

		// Work rise from weekly 100% overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_WEEKLY_100_PERCENT]));

		// Work rise from daily 50% overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_DAILY_50_PERCENT]));

		// Work rise from daily 100% overtime (1.5h daily 100% up to end of day (Mon - Fri) + 3.5h on Sat and Sun (because 100% daily is earned after weekly overtime) = 14.5h)
		$this->assertEquals(14.5 * 3600, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_DAILY_100_PERCENT]));
	}

	/**
	 * Tests new settings that allow defining whether work rise is earned from different types of overtime compensation
	 *
	 * @link https://gemini.nepton.com/workspace/0/item/16876
	 * @return void
	 */
	public function testWorkRise_ByOvertimeCompensationType_OnlyOvertimeWeekly50(): void {
		$testUser = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Work rise earning from overtime settings 2');
		$activityCustomPeriod = new ActivityCustomPeriod(new TimeSpan('2017-02-13 00:00:00', '2017-02-19 23:59:59'), $testUser);
		$settingValueProxy = $activityCustomPeriod->getSettingValueProxy();
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromBasicSalaryWork', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromAdditionalWork', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeDaily50', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeDaily100', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeWeekly50', 'Y');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeWeekly100', 'N');

		// With the work rise set to earn from all compensation types we should see the following
		$timeSpanCollection = $activityCustomPeriod->getTimeSpanCollectionCollection();

		// Work rise from basic salary time
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_BASIC_SALARY_TIME]));

		// Work rise from additional work overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_ADDITIONAL_WORK]));

		// Work rise from weekly 50% overtime
		$this->assertEquals(8 * 3600, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_WEEKLY_50_PERCENT]));

		// Work rise from weekly 100% overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_WEEKLY_100_PERCENT]));

		// Work rise from daily 50% overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_DAILY_50_PERCENT]));

		// Work rise from daily 100% overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_DAILY_100_PERCENT]));
	}

	/**
	 * Tests new settings that allow defining whether work rise is earned from different types of overtime compensation
	 *
	 * @link https://gemini.nepton.com/workspace/0/item/16876
	 * @return void
	 */
	public function testWorkRise_ByOvertimeCompensationType_OnlyOvertimeWeekly100(): void {
		$testUser = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Work rise earning from overtime settings 2');
		$activityCustomPeriod = new ActivityCustomPeriod(new TimeSpan('2017-02-13 00:00:00', '2017-02-19 23:59:59'), $testUser);
		$settingValueProxy = $activityCustomPeriod->getSettingValueProxy();
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromBasicSalaryWork', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromAdditionalWork', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeDaily50', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeDaily100', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeWeekly50', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeWeekly100', 'Y');

		// With the work rise set to earn from all compensation types we should see the following
		$timeSpanCollection = $activityCustomPeriod->getTimeSpanCollectionCollection();

		// Work rise from basic salary time
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_BASIC_SALARY_TIME]));

		// Work rise from additional work overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_ADDITIONAL_WORK]));

		// Work rise from weekly 50% overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_WEEKLY_50_PERCENT]));

		// Work rise from weekly 100% overtime
		$this->assertEquals(8 * 3600, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_WEEKLY_100_PERCENT]));

		// Work rise from daily 50% overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_DAILY_50_PERCENT]));

		// Work rise from daily 100% overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_DAILY_100_PERCENT]));
	}

	/**
	 * Tests new settings that allow defining whether work rise is earned from different types of overtime compensation
	 *
	 * @link https://gemini.nepton.com/workspace/0/item/16876
	 * @return void
	 */
	public function testWorkRise_ByOvertimeCompensationType_OnlySunday50(): void {
		$testUser = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Work rise earning from overtime settings 2');
		$activityCustomPeriod = new ActivityCustomPeriod(new TimeSpan('2017-02-13 00:00:00', '2017-02-19 23:59:59'), $testUser);
		$settingValueProxy = $activityCustomPeriod->getSettingValueProxy();
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromBasicSalaryWork', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromAdditionalWork', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeDaily50', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeDaily100', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeWeekly50', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeWeekly100', 'Y');

		// Sunday overtime calculations are off by default so we have to switch them on
		$settingValueProxy->fixSettingValue($testUser->getId(), 'maximum50OvertimeForSundaysAndBankHolidays', 2 * 60);
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeSunday50', 'Y');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeSunday100', 'N');


		// With the work rise set to earn from all compensation types we should see the following
		$timeSpanCollection = $activityCustomPeriod->getTimeSpanCollectionCollection();

		// Work rise from basic salary time
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_BASIC_SALARY_TIME]));

		// Work rise from additional work overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_ADDITIONAL_WORK]));

		// Work rise from weekly 50% overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_WEEKLY_50_PERCENT]));

		// Work rise from weekly 100% overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_WEEKLY_100_PERCENT]));

		// Work rise from daily 50% overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_DAILY_50_PERCENT]));

		// Work rise from daily 100% overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_DAILY_100_PERCENT]));

		// Work rise from Sunday 50% overtime
		$this->assertEquals(2 * 3600, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_SUNDAY_PUBLIC_HOLIDAY_50_PERCENT]));

		// Work rise from Sunday 100% overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_SUNDAY_PUBLIC_HOLIDAY_100_PERCENT]));
	}

	/**
	 * Tests new settings that allow defining whether work rise is earned from different types of overtime compensation
	 *
	 * @link https://gemini.nepton.com/workspace/0/item/16876
	 * @return void
	 */
	public function testWorkRise_ByOvertimeCompensationType_OnlySunday100(): void {
		$testUser = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Work rise earning from overtime settings 2');
		$activityCustomPeriod = new ActivityCustomPeriod(new TimeSpan('2017-02-13 00:00:00', '2017-02-19 23:59:59'), $testUser);
		$settingValueProxy = $activityCustomPeriod->getSettingValueProxy();
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromBasicSalaryWork', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromAdditionalWork', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeDaily50', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeDaily100', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeWeekly50', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeWeekly100', 'Y');

		// Sunday overtime calculations are off by default so we have to switch them on
		$settingValueProxy->fixSettingValue($testUser->getId(), 'maximum50OvertimeForSundaysAndBankHolidays', 2 * 60);
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeSunday50', 'N');
		$settingValueProxy->fixSettingValue($testUser->getId(), 'workRisesEarnedFromOvertimeSunday100', 'Y');


		// With the work rise set to earn from all compensation types we should see the following
		$timeSpanCollection = $activityCustomPeriod->getTimeSpanCollectionCollection();

		// Work rise from basic salary time
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_BASIC_SALARY_TIME]));

		// Work rise from additional work overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_ADDITIONAL_WORK]));

		// Work rise from weekly 50% overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_WEEKLY_50_PERCENT]));

		// Work rise from weekly 100% overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_WEEKLY_100_PERCENT]));

		// Work rise from daily 50% overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_DAILY_50_PERCENT]));

		// Work rise from daily 100% overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_DAILY_100_PERCENT]));

		// Work rise from Sunday 50% overtime
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_SUNDAY_PUBLIC_HOLIDAY_50_PERCENT]));

		// Work rise from Sunday 100% overtime (first 2h of Sunday are Sunday 50% so remaining 9.5h are Sunday 100%)
		$this->assertEquals(9.5 * 3600, $timeSpanCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['originatedFromArray' => ORIGINATED_OVERTIME_SUNDAY_PUBLIC_HOLIDAY_100_PERCENT]));
	}

	/**
	 * The time span query gave incorrect results when work rise was set to earn from basic salary working hours but
	 * only included activity types that don't earn basic salary working hours.
	 *
	 * @link https://gemini.nepton.com/workspace/0/item/16376
	 * @return void
	 */
	public function testWorkRise_fromMarkedType(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Work rise earning from marked type');
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		$activityDay = $activityCustomPeriod->getActivityDay('2016-09-05');
		$timeSpanCollection = $activityDay->getTimeSpanCollection();

		$this->assertEquals(3 * 3600, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		$this->assertEquals(1.5 * 3600, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRisePercentagePartByRiseNumber::class, ['number' => 1]));
		$this->assertEquals(2 * 3600, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 2]));
		$this->assertEquals(2 * 3600, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRisePercentagePartByRiseNumber::class, ['number' => 2]));
	}

	/**
	 * Test the earned part of the work rise can be targeted to an accrual/pay target based on the overtime target settings in the activity + setting hierarchy
	 * only included activity types that don't earn basic salary working hours.
	 *
	 * @link https://gemini.nepton.com/workspace/0/item/16760
	 * @return void
	 */
	public function testWorkRise_DynamicPayTarget_inheritOvertimeBasePartTarget(): void {

		// FOR THIS TEST, THE WORK RISE SHOULD BE PAID BASED ON THE *BASE* PART TARGET OF TIME OVER THE WORK DAY LENGTH

		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Work rise paid to base overtime target');
		$acp = new ActivityCustomPeriod(new TimeSpan('2017-01-16', '2017-01-16 23:59:59'), $user);

		// Event is set to uncompensated overtime so no work rise should be uncompensated too
		$activityDay = $acp->getActivityDay('2017-01-16');
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		$this->assertEquals(0, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BALANCE));
		$this->assertEquals(0, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BANK));

		// Event is set to hour to hour to salary, so work rise should be earned for the whole time and target salary
		$activityDay = $acp->getActivityDay('2017-01-17');
		$this->assertEquals(10.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1, 'workRiseAccrualTarget'=>ActivityPayTarget::SALARY]));
		$this->assertEquals(0, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BALANCE));
		$this->assertEquals(0, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BANK));

		// Event is set to overtime to salary, so work rise should be earned for the whole time and target salary
		$activityDay = $acp->getActivityDay('2017-01-18');
		$this->assertEquals(10.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1, 'workRiseAccrualTarget'=>ActivityPayTarget::SALARY]));
		$this->assertEquals(0, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BALANCE));
		$this->assertEquals(0, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BANK));

		// Event is set to hour to hour to balance, so work rise should be earned for the whole time and target balance (10.5h from work rise + 3h from normal time over work day to balance)
		$activityDay = $acp->getActivityDay('2017-01-19');
		$this->assertEquals(10.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1, 'workRiseAccrualTarget'=>ActivityPayTarget::BALANCE]));
		$this->assertEquals(13.5 * 3600, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BALANCE));
		$this->assertEquals(0, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BANK));

		// Event is set to hour to hour to bank, so work rise should be earned for the whole time and target bank (10.5h from work rise + 3h from normal time over work day to bank)
		$activityDay = $acp->getActivityDay('2017-01-20');
		$this->assertEquals(10.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1, 'workRiseAccrualTarget'=>ActivityPayTarget::BANK]));
		$this->assertEquals(0, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BALANCE));
		$this->assertEquals(13.5 * 3600, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BANK));

		// Event is set to overtime to balance, so work rise should be earned for the whole time and target balance (10.5h from work rise + 4.5h from normal overtime to balance with overtime multipliers)
		$activityDay = $acp->getActivityDay('2017-01-23');
		$this->assertEquals(10.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1, 'workRiseAccrualTarget'=>ActivityPayTarget::BALANCE]));
		$this->assertEquals(15 * 3600, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BALANCE));
		$this->assertEquals(0, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BANK));

		// Event is set to overtime to bank, so work rise should be earned for the whole time and target bank (10.5h from work rise + 4.5h from normal overtime to bank with overtime multipliers)
		$activityDay = $acp->getActivityDay('2017-01-24');
		$this->assertEquals(10.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1, 'workRiseAccrualTarget'=>ActivityPayTarget::BANK]));
		$this->assertEquals(0, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BALANCE));
		$this->assertEquals(15 * 3600, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BANK));

		// Event is set basePart=balance, overtimePart=salary, so work rise should be earned for the whole time and target bank (10.5h from work rise + 4.5h from normal overtime to bank with overtime multipliers)
		$activityDay = $acp->getActivityDay('2017-01-24');
		$this->assertEquals(10.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1, 'workRiseAccrualTarget'=>ActivityPayTarget::BANK]));
		$this->assertEquals(0, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BALANCE));
		$this->assertEquals(15 * 3600, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BANK));

		// Event is set basePart=balance, overtimePart=salary, and setting says to use the target from the base part so this should work the same as 19th
		$activityDay = $acp->getActivityDay('2017-01-25');
		$this->assertEquals(10.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1, 'workRiseAccrualTarget'=>ActivityPayTarget::BALANCE]));
		$this->assertEquals(13.5 * 3600, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BALANCE));
		$this->assertEquals(0, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BANK));

		// Event is set basePart=bank, overtimePart=salary, and setting says to use the target from the base part so this should work the same as 20th
		$activityDay = $acp->getActivityDay('2017-01-26');
		$this->assertEquals(10.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1, 'workRiseAccrualTarget'=>ActivityPayTarget::BANK]));
		$this->assertEquals(0, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BALANCE));
		$this->assertEquals(13.5 * 3600, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BANK));

		// Event is set to inherit overtime target from settings (which = basePart=balance, overtimePart=salary), and setting says to use the target from the base part so this should work the same as 25th
		$activityDay = $acp->getActivityDay('2017-01-27');
		$this->assertEquals(10.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1, 'workRiseAccrualTarget'=>ActivityPayTarget::BALANCE]));
		$this->assertEquals(13.5 * 3600, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BALANCE));
		$this->assertEquals(0, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BANK));
	}

	/**
	 * Test the earned part of the work rise can be targeted to an accrual/pay target based on the overtime target settings in the activity + setting hierarchy
	 * only included activity types that don't earn basic salary working hours.
	 *
	 * @link https://gemini.nepton.com/workspace/0/item/16760
	 * @return void
	 */
	public function testWorkRise_DynamicPayTarget_inheritOvertimeIncrementedPartTarget(): void {

		// FOR THIS TEST, THE WORK RISE SHOULD BE PAID BASED ON THE *INCREMENTED/MULTIPLIER* PART TARGET OF TIME OVER THE WORK DAY LENGTH

		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Work rise paid to incremented overtime target');
		$acp = new ActivityCustomPeriod(new TimeSpan('2017-01-16', '2017-01-16 23:59:59'), $user);

		// Event is set to uncompensated overtime so no work rise should be uncompensated too
		$activityDay = $acp->getActivityDay('2017-01-16');
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		$this->assertEquals(0, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BALANCE));
		$this->assertEquals(0, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BANK));

		// Event is set to hour to hour to salary, so no work rise should be earned because we are using the incremented part, and with hour to hour time there is no incremented part
		$activityDay = $acp->getActivityDay('2017-01-17');
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		$this->assertEquals(0, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BALANCE));
		$this->assertEquals(0, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BANK));

		// Event is set to overtime to salary, so work rise should be earned for the whole time and target salary
		$activityDay = $acp->getActivityDay('2017-01-18');
		$this->assertEquals(10.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1, 'workRiseAccrualTarget'=>ActivityPayTarget::SALARY]));
		$this->assertEquals(0, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BALANCE));
		$this->assertEquals(0, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BANK));

		// Event is set to hour to hour to balance, so no work rise should be earned because we are using the incremented part, and with hour to hour time there is no incremented part
		$activityDay = $acp->getActivityDay('2017-01-19');
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		$this->assertEquals(3 * 3600, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BALANCE)); // from normal hour to hour time, not from work rise
		$this->assertEquals(0, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BANK));

		// Event is set to hour to hour to bank, so no work rise should be earned because we are using the incremented part, and with hour to hour time there is no incremented part
		$activityDay = $acp->getActivityDay('2017-01-20');
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		$this->assertEquals(0, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BALANCE));
		$this->assertEquals(3 * 3600, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BANK)); // from normal hour to hour time, not from work rise

		// Event is set to overtime to balance, so work rise should be earned for the whole time and target balance (10.5h from work rise + 4.5h from normal overtime to balance with overtime multipliers)
		$activityDay = $acp->getActivityDay('2017-01-23');
		$this->assertEquals(10.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1, 'workRiseAccrualTarget'=>ActivityPayTarget::BALANCE]));
		$this->assertEquals(15 * 3600, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BALANCE));
		$this->assertEquals(0, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BANK));

		// Event is set to overtime to bank, so work rise should be earned for the whole time and target bank (10.5h from work rise + 4.5h from normal overtime to bank with overtime multipliers)
		$activityDay = $acp->getActivityDay('2017-01-24');
		$this->assertEquals(10.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1, 'workRiseAccrualTarget'=>ActivityPayTarget::BANK]));
		$this->assertEquals(0, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BALANCE));
		$this->assertEquals(15 * 3600, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BANK));

		// Event is set basePart=balance, overtimePart=salary, so work rise should be earned for the whole time and target bank (10.5h from work rise + 4.5h from normal overtime to bank with overtime multipliers)
		$activityDay = $acp->getActivityDay('2017-01-24');
		$this->assertEquals(10.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1, 'workRiseAccrualTarget'=>ActivityPayTarget::BANK]));
		$this->assertEquals(0, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BALANCE));
		$this->assertEquals(15 * 3600, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BANK));

		// Event is set basePart=balance, overtimePart=salary, and setting says to use the target from the incremented part
		$activityDay = $acp->getActivityDay('2017-01-25');
		$this->assertEquals(10.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1, 'workRiseAccrualTarget'=>ActivityPayTarget::SALARY]));
		$this->assertEquals(3 * 3600, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BALANCE)); // from normal hour to hour time, not from work rise
		$this->assertEquals(0, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BANK));

		// Event is set basePart=bank, overtimePart=salary, and setting says to use the target from the incremented part so
		$activityDay = $acp->getActivityDay('2017-01-26');
		$this->assertEquals(10.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1, 'workRiseAccrualTarget'=>ActivityPayTarget::SALARY]));
		$this->assertEquals(0, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BALANCE));
		$this->assertEquals(3 * 3600, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BANK)); // from normal hour to hour time, not from work rise

		// Event is set to inherit overtime target from settings (which = basePart=balance, overtimePart=salary), and setting says to use the target from the base part so it should work the same as the 25th
		$activityDay = $acp->getActivityDay('2017-01-27');
		$this->assertEquals(10.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1, 'workRiseAccrualTarget'=>ActivityPayTarget::SALARY]));
		$this->assertEquals(3 * 3600, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BALANCE));
		$this->assertEquals(0, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BANK));
	}

	/**
	 * If time is increased due to minimum work time compensation, work rise should not automatically be earned from that. This is a use case for Säästöpankki
	 *
	 * @link https://gemini.nepton.com/workspace/0/item/16750
	 * @return void
	 */
	public function testWorkRise_NotEarnedFromMinimumWorkTimeCompensation(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Work rise not earned from min work time comp');
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		$activityDay = $activityCustomPeriod->getActivityDay('2017-01-07');
		$timeSpanCollection = $activityDay->getTimeSpanCollection();

		$this->assertEquals(4.5 * 3600, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		$this->assertEquals((7 * 3600) + (24 * 60), $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanPaidTime::class, ['basePartTarget' => ActivityPayTarget::BANK]));
		$this->assertEquals(4.5 * 3600, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanPaidTime::class, [], ['originatedFromArray' => ORIGINATED_MINIMUM_WORK_TIME_COMPENSATION]));
	}

	/**
	 * There was a bug with the work rise not earned if overtime starts before/after settings when the overtime started after midnight
	 *
	 * @link https://gemini.nepton.com/workspace/0/item/16532
	 * @return void
	 */
	public function testWorkRise_WorkRiseOvertimeEarningThresholdsOvernight(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Work rise overtime earning thresholds');
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);

		// On Monday shift the overtime starts after midnight so "Earned If OT after 10pm" should be earned and NOT "Earned If OT b4 10pm" (this was the bug before that it didn't work overnight)
		$activityDay = $activityCustomPeriod->getActivityDay('2016-11-21');
		$timeSpanCollection = $activityDay->getTimeSpanCollection();
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		$this->assertEquals(6 * 3600, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 2])); // 00:00 -> 06:00

		// On Tuesday shift the overtime starts before 10PM so "Earned If OT after 10pm" should be earned and NOT "Earned If OT b4 10pm"
		$activityDay = $activityCustomPeriod->getActivityDay('2016-11-22');
		$timeSpanCollection = $activityDay->getTimeSpanCollection();
		$this->assertEquals(2 * 3600, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1])); // 22:00 -> 24:00
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 2]));
	}

	/**
	 * Tests that rounding works correctly with several activities.
	 * A separate percentage part span was used to be created to each TimeSpanWorkRise span, all of them rounded to minutes.
	 * That situation resulted in having incorrect total amount of percentage hours.
	 * covers SalaryConceptWorkRises::earnWorkRisePercentagePart
	 * @link https://gemini.nepton.com/workspace/0/item/9101
	 * @return void
	 */
	public function testEarnWorkRisePercentagePart_percentagesWithManyActivities(): void {
		$activityCustomPeriod = new ActivityCustomPeriod(null, $this->userRepository->selectUserByUserId(17997)); // 7552 UnitTest - UT_Vanilla
		$activityDay = $activityCustomPeriod->getActivityDay('2013-05-28');
		$this->assertEquals(45 * 60, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRisePercentagePartByRiseNumber::class, ['number' => 1]));
	}

	/**
	 * covers SalaryConceptWorkRises::earnWorkRisePercentagePart
	 * @link https://gemini.nepton.com/workspace/29/item/9395
	 * @return void
	 */
	public function testEarnWorkRisePercentagePart_WithSalaryRenderingMode_ByDay(): void {

		$user = $this->userRepository->selectUserByUserId(22066); // Leipomo Rosten
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		$activityCustomPeriod->getSettingValueProxy()->fixSettingValue($user->getId(), 'salaryRenderingMode', SALARY_RENDERING_MODE_BY_DAY);
		$activityDay = $activityCustomPeriod->getActivityDay('2014-07-03');
		$timeSpanCollection = $activityDay->getTimeSpanCollection();

		$this->assertEquals(60 * 0.15 * 60, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRisePercentagePartByRiseNumber::class, ['number' => 1])); // 15% of 1h work rise (Iltalisä) earned up till midnight
		$this->assertEquals(3 * 60 * 60, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRisePercentagePartByRiseNumber::class, ['number' => 2])); // 100% of 3h work rise (Yölisä) earned up till midnight
	}

	/**
	 * covers SalaryConceptWorkRises::earnWorkRisePercentagePart
	 * @link https://gemini.nepton.com/workspace/29/item/9395
	 * @return void
	 */
	public function testEarnWorkRisePercentagePart_WithSalaryRenderingMode_ByShiftStart(): void {

		$user = $this->userRepository->selectUserByUserId(22066);
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		$activityCustomPeriod->getSettingValueProxy()->fixSettingValue($user->getId(), 'salaryRenderingMode', SALARY_RENDERING_MODE_BY_SHIFT_START);
		$activityDay = $activityCustomPeriod->getActivityDay('2014-07-03'); // Leipomo Rosten
		$timeSpanCollection = $activityDay->getTimeSpanCollection();

		$this->assertEquals(60 * 0.15 * 60, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRisePercentagePartByRiseNumber::class, ['number' => 1])); // 15% of 1h work rise (Iltalisä) earned till end of nightshift TOMORROW
		$this->assertEquals(7 * 60 * 60, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRisePercentagePartByRiseNumber::class, ['number' => 2])); // 100% of 7h work rise (Yölisä) earned till end of nightshift TOMORROW
	}

	/**
	 * covers SalaryConceptWorkRises::earnWorkRisePercentagePart
	 * @link https://gemini.nepton.com/workspace/29/item/9395
	 * @return void
	 */
	public function testEarnWorkRisePercentagePart_WithSalaryRenderingMode_ByShiftEnd(): void {

		$user = $this->userRepository->selectUserByUserId(22066); // Leipomo Rosten
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		$activityCustomPeriod->getSettingValueProxy()->fixSettingValue($user->getId(), 'salaryRenderingMode', SALARY_RENDERING_MODE_BY_SHIFT_END);
		$activityDay = $activityCustomPeriod->getActivityDay('2014-07-03');
		$timeSpanCollection = $activityDay->getTimeSpanCollection();

		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRisePercentagePartByRiseNumber::class, ['number' => 1])); // We shouldn't earn anything as no night shifts ended today
		$this->assertEquals(0, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRisePercentagePartByRiseNumber::class, ['number' => 2])); // We shouldn't earn anything as no night shifts ended today

		$activityDay = $activityCustomPeriod->getActivityDay('2014-07-04');
		$activityDay->getSettingValueProxy()->fixSettingValue($user->getId(), 'salaryRenderingMode', SALARY_RENDERING_MODE_BY_SHIFT_END);
		$timeSpanCollection = $activityDay->getTimeSpanCollection();

		$this->assertEquals(60 * 0.15 * 60, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRisePercentagePartByRiseNumber::class, ['number' => 1])); // 15% of 1h work rise (Iltalisä) earned from nightshift ending today
		$this->assertEquals(7 * 60 * 60, $timeSpanCollection->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRisePercentagePartByRiseNumber::class, ['number' => 2])); // 100% of 7h work rise (Yölisä) earned from nightshift ending today
	}

	/**
	 * Tests that minutes per hour calculation method works correctly.
	 * covers SalaryConceptWorkRises::earnWorkRisePercentagePart
	 * @link https://gemini.nepton.com/workspace/0/item/11791
	 * @return void
	 */
	public function testEarnWorkRisePercentagePart_percentagesByMinutes(): void {
		$user = $this->userRepository->selectUserByUserId(17997); // 7552 UnitTest - UT_Vanilla
		$settingValueProxy = SettingValueProxy::getInstance();
		$settingValueProxy->fixSettingValue($user->getId(), 'TimeDependantWorkRiseIncreaseMethod', 'minutes');
		$settingValueProxy->fixSettingValue($user->getId(), 'TimeDependantWorkRiseMinuteIncrease', 7);
		$settingValueProxy->fixSettingValue($user->getId(), 'workRiseAccrualTarget', ActivityPayTarget::SALARY);

		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		$activityCustomPeriod->setSettingValueProxy($settingValueProxy);
		$activityDay = $activityCustomPeriod->getActivityDay('2013-05-28');
		//$activities = $activityDay->getActivityArray();
		/** @var Activity $lastActivity */
		//$lastActivity = end($activities);
		// Edit last activity so that the user will earn work rise for 4h 30min
		//$lastActivity->setEndDateTime('2013-05-28 20:30:00');
		// Increase is only given for full hours, so it is paid for 4 hours
		$this->assertEquals(5 * 7 * 60, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRisePercentagePartByRiseNumber::class, ['number' => 1]));
	}

	/**
	 * covers SalaryConceptWorkRises::earnWorkRisePercentagePart
	 * @link https://gemini.nepton.com/workspace/0/item/13481
	 * @return void
	 */
	public function testEarnWorkRisePercentagePart_negativePercentage(): void {
		$user = $this->userRepository->selectUserByUserId(17997); // 7552 UnitTest - UT_Vanilla
		$settingValueProxy = SettingValueProxy::getInstance();
		$settingValueProxy->fixSettingValue($user->getId(), 'TimeDependantWorkRiseIncreaseMethod', 'percent');
		$settingValueProxy->fixSettingValue($user->getId(), 'TimeDependantWorkRisePercentage', -50);
		$settingValueProxy->fixSettingValue($user->getId(), 'workRiseAccrualTarget', ActivityPayTarget::BALANCE);

		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		$activityCustomPeriod->setSettingValueProxy($settingValueProxy);
		$activityDay = $activityCustomPeriod->getActivityDay('2013-05-28');
		$this->assertEquals(5 * 3600 * -0.5, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRiseNegativePercentagePartByRiseNumber::class, ['number' => 1, 'workRiseAccrualTarget' => ActivityPayTarget::BALANCE], null, 'value'));
	}

	/**
	 * Negative work rise percentage is not supported with salary.
	 * covers SalaryConceptWorkRises::earnWorkRisePercentagePart
	 * @link https://gemini.nepton.com/workspace/0/item/13481
	 * @return void
	 */
	public function testEarnWorkRisePercentagePart_negativePercentage_toSalary(): void {
		$user = $this->userRepository->selectUserByUserId(17997); // 7552 UnitTest - UT_Vanilla
		$settingValueProxy = SettingValueProxy::getInstance();
		$settingValueProxy->fixSettingValue($user->getId(), 'TimeDependantWorkRiseIncreaseMethod', 'percent');
		$settingValueProxy->fixSettingValue($user->getId(), 'TimeDependantWorkRisePercentage', -50);
		$settingValueProxy->fixSettingValue($user->getId(), 'workRiseAccrualTarget', ActivityPayTarget::SALARY);

		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		$activityCustomPeriod->setSettingValueProxy($settingValueProxy);
		$activityDay = $activityCustomPeriod->getActivityDay('2013-05-28');
		$activities = $activityDay->getActivityArray();
		/** @var Activity $lastActivity */
		$lastActivity = end($activities);
		// Edit last activity so that the user will earn work rise for 4h 30min
		$lastActivity->setEndDateTime('2013-05-28 15:30:00');
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRiseNegativePercentagePartByRiseNumber::class, ['number' => 1, 'workRiseAccrualTarget' => ActivityPayTarget::BALANCE], null, 'value'));
	}

	/**
	 * covers SalaryConceptWorkRises::earnWorkRisePercentagePart
	 * @link https://gemini.nepton.com/workspace/0/item/16121
	 * @return void
	 */
	public function testEarnWorkRisePercentagePart_uncompensatedAccrualTarget(): void {
		$user = $this->userRepository->selectUserByUserId(17997); // 7552 UnitTest - UT_Vanilla
		$settingValueProxy = SettingValueProxy::getInstance();
		$settingValueProxy->fixSettingValue($user->getId(), 'TimeDependantWorkRiseIncreaseMethod', 'percent');
		$settingValueProxy->fixSettingValue($user->getId(), 'TimeDependantWorkRisePercentage', 50);
		$settingValueProxy->fixSettingValue($user->getId(), 'workRiseAccrualTarget', ActivityPayTarget::UNCOMPENSATED);

		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		$activityCustomPeriod->setSettingValueProxy($settingValueProxy);
		$activityDay = $activityCustomPeriod->getActivityDay('2013-05-28');
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRiseNegativePercentagePartByRiseNumber::class, ['number' => 1, 'workRiseAccrualTarget' => ActivityPayTarget::BALANCE], null, 'value'));
	}

	/**
	 * covers SalaryConceptWorkRises::earnWorkRisePercentagePart
	 * @link https://gemini.nepton.com/workspace/0/item/17416
	 * @return void
	 */
	public function testEarnWorkRisePercentagePart_percentagePartAsDecimal(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Work rises decimal increase percentage');
		$activityCustomPeriod = new ActivityCustomPeriod(new TimeSpan('2016-07-11 00:00:00', '2016-07-11 23:59:59'), $user);
		$activityDay = $activityCustomPeriod->getActivityDay('2016-07-11');
		$this->assertEquals((4 * 3600) + (48 * 60), $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRisePercentagePartByRiseNumber::class, ['number' => 1, 'workRiseAccrualTarget' => ActivityPayTarget::BALANCE]));
	}

	/**
	 * covers SalaryConceptWorkRises::earnWorkRisePercentagePart
	 * @link https://dev.azure.com/neptongroup/Nepton%20Dev/_workitems/edit/1435
	 * @return void
	 */
	public function testEarnWorkRisePercentagePart_negativeTimeTakenFromAccrual(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTDEPLOYMENT Negative work rise to custom accrual');
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);
		$activityDay = $activityCustomPeriod->getActivityDay('2017-10-27');
		$this->assertEquals((7 * 3600) + (30 * 60), $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 11, 'workRiseAccrualTarget' => ActivityPayTarget::BANK]));
		$this->assertEquals(-3.75 * 3600, $activityDay->getTimeSpanCollection()->getTimeSpanCollectionByTimeSpanClassName(TimeSpanWorkRiseNegativePercentagePartByRiseNumber::class, ['number' => 11, 'workRiseAccrualTarget' => ActivityPayTarget::BANK])->durationInSeconds('value'));
		$this->assertEquals(-3.75 * 3600, $activityDay->getAccrualChangeAmount(ActivityPayTarget::BANK));
	}

	/**
	 * Test that if you earn work rise as fixed compensation and that compensation is longer
	 * than the original activity, that it cannot spill to the next day that the activity doesn't touch)
	 *
	 * Note: this is only a test of what the hack covers as we do not have time to fix it properly, see linked
	 *
	 * covers SalaryConceptWorkRises::earnWorkRisePercentagePart
	 * @link https://dev.azure.com/neptongroup/Nepton%20Dev/_workitems/edit/7147 (and see linked ticket for proper fix required)
	 * @return void
	 */
	public function testEarnWorkRisePercentagePart_fixedCompensationCannotSpillOvernight(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Work rise fixed compensation overnight');
		$activityCustomPeriod = new ActivityCustomPeriod(new TimeSpan('2019-03-11', '2019-03-18'), $user);

		// With the bug present, the 3h earned work rise (2h longer than the size of the activity) would incorrectly
		// spill onto the next day causing an activity to be shown there (with byDay salary rendering)
		// so check it's all earned on the day the activity is marked on and nothing has spilled over
		$activityDay = $activityCustomPeriod->getActivityDay('2019-03-11');
		$this->assertEquals(3 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class));
		$this->assertCount(1, $activityDay->getActivityArray());
		$activityDay = $activityCustomPeriod->getActivityDay('2019-03-12');
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class));
		$this->assertCount(0, $activityDay->getActivityArray());

		// Same test but work rise earned at the beginning of the day that shouldn't spill to the previous day
		$activityDay = $activityCustomPeriod->getActivityDay('2019-03-18');
		$this->assertEquals(3 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class));
		$this->assertCount(1, $activityDay->getActivityArray());
		$activityDay = $activityCustomPeriod->getActivityDay('2019-03-17');
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class));
		$this->assertCount(0, $activityDay->getActivityArray());
	}

	/**
	 * With salary rendering mode "by day" we have to split pay to the day it falls on. With
	 * time rounded up we have to be careful it doesn't confusingly end up paying time to a day the activity
	 * doesn't belong to.
	 *
	 * @link https://dev.azure.com/neptongroup/Nepton%20Dev/_workitems/edit/7376
	 * @return void
	 */
	public function testRoundedActivityEarnsWorkRiseCorrectlyOvernight(): void {
		$user                 = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Rounded time overnight goes to correct day');
		$activityCustomPeriod = new ActivityCustomPeriod(new TimeSpan('2019-05-03', '2019-05-11'), $user);

		// Activity ends a couple of minutes after midnight and gets rounded up to 00:15.
		// This failed at some point because we tried to push the rounded time back over the activity. The rounded time was created after midnight and got accidentally pushed
		// back over midnight, resulting in a weird distribution of pay
		$this->assertEquals(2 * 3600, $activityCustomPeriod->getActivityDay('2019-05-03')->getTimeSpanCollection()->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class));
		$this->assertEquals(15 * 60, $activityCustomPeriod->getActivityDay('2019-05-04')->getTimeSpanCollection()->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class));

		// This activity ends a couple of minutes before midnight and gets rounded up to midnight
		$this->assertEquals(2 * 3600, $activityCustomPeriod->getActivityDay('2019-05-10')->getTimeSpanCollection()->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class));
		$this->assertEquals(0, $activityCustomPeriod->getActivityDay('2019-05-11')->getTimeSpanCollection()->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class));
	}

	/**
	 * With salary rendering mode "by day" we have to split pay to the day it falls on. With
	 * time rounded up we have to be careful it doesn't confusingly end up paying time to a day the activity
	 * doesn't belong to. This test adds 6h fixed compensation to the work rise to ensure time ending before midnight
	 * spills over midnight
	 *
	 * @link https://dev.azure.com/neptongroup/Nepton%20Dev/_workitems/edit/7376
	 * @return void
	 */
	public function testRoundedActivityEarnsWorkRiseCorrectlyOvernight_WithFixedCompensation(): void {
		$user                 = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Rounded time overnight goes to correct day');
		$activityCustomPeriod = new ActivityCustomPeriod(new TimeSpan('2019-05-03', '2019-05-11'), $user);

		// Fix work rise compensation to 6h
		$activityCustomPeriod->getSettingValueProxy()->fixSettingValue($user->getId(), 'workRiseFixedCompensationAmount', 360, 3); // Work rise 3 = Night rise

		// Activity ends a couple of minutes after midnight and gets rounded up to 00:15.
		// There is 2h before midnight so 2h night rise is earned on the Friday
		// and 0:15 of rounded work time on the Saturday but because we have fixed work rise compensation of 6h there is 4h left to pay
		$this->assertEquals(2 * 3600, $activityCustomPeriod->getActivityDay('2019-05-03')->getTimeSpanCollection()->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class));
		$this->assertEquals(4 * 3600, $activityCustomPeriod->getActivityDay('2019-05-04')->getTimeSpanCollection()->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class));

		// This activity ends a couple of minutes before midnight and gets rounded up to midnight
		// Ensure the 6h of fixed work rise compensation from the activity doesn't spill onto the next day
		$this->assertEquals(6 * 3600, $activityCustomPeriod->getActivityDay('2019-05-10')->getTimeSpanCollection()->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class));
		$this->assertEquals(0, $activityCustomPeriod->getActivityDay('2019-05-11')->getTimeSpanCollection()->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class));
	}

	/**
	 * @link https://dev.azure.com/neptongroup/Nepton%20Dev/_workitems/edit/7231
	 * @return void
	 */
	public function testEarnWorkRisePercentagePart_CompensationCannotSpillOvernight_2(): void {
		$this->markTestIncomplete('TODO, test earning work rise amounts over 24h to ensure it cannot spill onto other days (unless the activity does so too)'); // @TODO
	}

	/**
	 * covers SalaryConceptWorkRises::earnWorkRisePercentagePart
	 * @return void
	 */
	public function testMinimumEarningThreshold(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Work rise earning threshold');
		$activityCustomPeriod = new ActivityCustomPeriod(new TimeSpan('2017-02-06 00:00:00', '2017-02-21 23:59:59'), $user);

		// Day one, no earning as below threshold
		$activityDay = $activityCustomPeriod->getActivityDay('2017-02-06');
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));

		// Day two, earned normally as threshold exceeded
		$activityDay = $activityCustomPeriod->getActivityDay('2017-02-07');
		$this->assertEquals(2 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));

		// Day three, no earning as below threshold. This should happen even when there is minimum earning amount set
		$activityDay = $activityCustomPeriod->getActivityDay('2017-02-13');
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));

		// Day three, as threshold has been exceeded and minimum earned amount is 4 hours, that should be earned
		$activityDay = $activityCustomPeriod->getActivityDay('2017-02-14');
		$this->assertEquals(4 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));

		// Day three, no earning as below threshold. This should happen even when there is a fixed earning amount set
		$activityDay = $activityCustomPeriod->getActivityDay('2017-02-20');
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));

		// Day three, as threshold has been exceeded and fixed earned amount is 7 hours, that should be earned
		$activityDay = $activityCustomPeriod->getActivityDay('2017-02-21');
		$this->assertEquals(7 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
	}

	/**
	 * covers SalaryConceptWorkRises::earnWorkRisePercentagePart
	 * @link https://gemini.nepton.com/workspace/0/item/17616
	 * @return void
	 */
	public function testMaximumEarningThreshold(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Work rise earning threshold');
		$activityCustomPeriod = new ActivityCustomPeriod(new TimeSpan('2017-03-06 00:00:00', '2017-03-19 23:59:59'), $user);

		// Day one, earned normally as below threshold
		$activityDay = $activityCustomPeriod->getActivityDay('2017-03-06');
		$this->assertEquals(1 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 2]));

		// Day two, not earned as over threshold
		$activityDay = $activityCustomPeriod->getActivityDay('2017-03-07');
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 2]));

		// Day three, as threshold has not been exceeded and fixed earned amount is 7 hours, that should be earned
		$activityDay = $activityCustomPeriod->getActivityDay('2017-03-13');
		$this->assertEquals(7 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 2]));

		// Day four, not earned as over threshold
		$activityDay = $activityCustomPeriod->getActivityDay('2017-03-14');
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 2]));
	}

	/**
	 * covers SalaryConceptWorkRises::earnWorkRisePercentagePart
	 * @link https://gemini.nepton.com/workspace/0/item/17616
	 * @return void
	 */
	public function testEarnedFromUnpaidOvertime(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Work rise from unpaid overtime');
		$activityCustomPeriod = new ActivityCustomPeriod(null, $user);

		// Work rise "NOT from unpaid overtime" should only be earned before the unpaid overtime
		$activityDay = $activityCustomPeriod->getActivityDay('2017-05-31');
		$this->assertEquals(1 * 3600 + (24 * 60), $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		$earliestUnpaidOvertime = $activityDay->getTimeSpanCollection()->getEarliestTimeSpan(TimeSpanUnpaidOvertime::class)->getBeginDateTime();
		$latestWorkRiseEarned = $activityDay->getTimeSpanCollection()->getLatestTimeSpan(TimeSpanWorkRise::class, ['number' => 1])->getEndDateTime();
		$this->assertLessThanOrEqual($earliestUnpaidOvertime, $latestWorkRiseEarned);

		// Work rise "FROM unpaid overtime" should earn from the unpaid overtime after 11PM
		$this->assertEquals(0.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 2]));
		$this->assertEquals('2017-05-31 23:00:00', $activityDay->getTimeSpanCollection()->getLatestTimeSpan(TimeSpanWorkRise::class, ['number' => 2])->getBeginDateTime());
	}

	/**
	 * Week day name was not reset when switching to next work rise. This could lead into a situation where
	 * workRiseCalculationWeekDay setting would set the week day name to be something else than it really is for next
	 * work rises and botching up work rise calculations.
	 * @link https://gemini.nepton.com/workspace/0/item/18109
	 * @return void
	 */
	public function testWorkRiseCalculationWeekDayNotRememberedFromPreviousRise(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Work Rise Calculation WeekDay');
		$activityCustomPeriod = new ActivityCustomPeriod(new TimeSpan('2017-12-25 00:00:00', '2017-12-25 23:59:59'), $user);

		$activityDay = $activityCustomPeriod->getActivityDay('2017-12-25');
		$this->assertEquals(7.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		$this->assertEquals(0 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 2]));
	}

	/**
	 * Tests that we can force paid time during work rise to be forced to be paid to salary as additional work
	 * @return void
	 */
	public function testWorkRises_forcePayTargetAsAdditionalWorkToSalary(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Work rise force additional work');
		$activityCustomPeriod = new ActivityCustomPeriod(new TimeSpan('2017-08-07 00:00:00', '2017-08-09 23:59:59'), $user);

		$activityDay = $activityCustomPeriod->getActivityDay('2017-08-07');
		$this->assertEquals(5.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		$this->assertEquals(5.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanOvertimeAdditionalWork::class, ['originatedFromArray' => [ORIGINATED_BASIC_TIME_FORCED_TO_SALARY_TYPE_BY_WORK_RISE], 'basePartTarget' => ActivityPayTarget::BALANCE, 'overtimeIncrementPartTargetedTo' => ActivityPayTarget::BALANCE, 'overtimeRisePercentage' => 0]));

		$activityDay = $activityCustomPeriod->getActivityDay('2017-08-08');
		$this->assertEquals(5.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		$this->assertEquals(5.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanOvertimeAdditionalWork::class, ['originatedFromArray' => [ORIGINATED_UNPAID_OVERTIME_FORCED_TO_SALARY_TYPE_BY_WORK_RISE], 'basePartTarget' => ActivityPayTarget::SALARY, 'overtimeIncrementPartTargetedTo' => ActivityPayTarget::SALARY, 'overtimeRisePercentage' => 0]));

		$activityDay = $activityCustomPeriod->getActivityDay('2017-08-09');
		$this->assertEquals(5.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		$this->assertEquals(5.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanOvertimeAdditionalWork::class, ['originatedFromArray' => [ORIGINATED_NON_ADDITIONAL_OVERTIME_FORCED_TO_SALARY_TYPE_BY_WORK_RISE], 'basePartTarget' => ActivityPayTarget::SALARY, 'overtimeIncrementPartTargetedTo' => ActivityPayTarget::SALARY, 'overtimeRisePercentage' => 0]));
	}

	/**
	 * Tests "do not earn work rise if these rise(s) have been earned" setting
	 *
	 * This works in a following way: Two work rises
	 * - Work rise 1 (12-24), do not earn if rise 2 has been earned
	 * - Work rise 2 (00-12), do not earn if rise 1 has been earned
	 *
	 * With work from 11-18, only rise 2 will be earned
	 * With work from 22-06, only rise 1 will be earned
	 *
	 * @link https://gemini.nepton.com/workspace/0/item/18081
	 * @return void
	 */
	public function testWorkRises_workRiseNotEarnedIfRiseNumbersHaveBeenEarned(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Blocking work rise numbers');
		$activityCustomPeriod = new ActivityCustomPeriod(new TimeSpan('2017-08-07 00:00:00', '2017-08-09 23:59:59'), $user);

		$activityDay = $activityCustomPeriod->getActivityDay('2017-08-07');
		$this->assertEquals(5.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 2]));

		$activityDay = $activityCustomPeriod->getActivityDay('2017-08-08');
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		$this->assertEquals(8 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 2]));

		$activityDay = $activityCustomPeriod->getActivityDay('2017-08-09');
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		$this->assertEquals(2 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 2]));
	}

	/**
	 * @link https://dev.azure.com/neptongroup/Nepton%20Dev/_workitems/edit/924
	 * @return void
	 */
	public function testWorkRises_forcePayTargetWithAgreedOvertimeTypes(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER WorkRise ForcedEarnedTimeWithAgreedOvertime');
		$activityCustomPeriod = new ActivityCustomPeriod(new TimeSpan('2017-09-04 00:00:00', '2017-09-05 23:59:59'), $user);

		// Both days have assumed lunch of 30 minutes & 20 minutes of that lunch is paid
		// @link https://dev.azure.com/neptongroup/Nepton%20Dev/_workitems/edit/6916

		$activityDay = $activityCustomPeriod->getActivityDay('2017-09-04');
		$this->assertEquals((9 * 3600) + (20 * 60), $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		$this->assertEquals((9 * 3600) + (20 * 60), $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanOvertimeDaily100::class));

		$activityDay = $activityCustomPeriod->getActivityDay('2017-09-05');
		$this->assertEquals((9 * 3600) + (20 * 60), $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
		$this->assertEquals((9 * 3600) + (20 * 60), $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanOvertimeDaily100::class));
	}

	/**
	 * Tests that it is possible to earn work rise from accrual leave events.
	 * Work rise that can be earned from bank leave is valid on every day during 15:00-24:00
	 *
	 * @link https://dev.azure.com/neptongroup/Nepton%20Dev/_workitems/edit/5182
	 * @return void
	 */
	public function testWorkRises_earnedFromAccrualLeave(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Work rise from accrual leave');
		$activityCustomPeriod = new ActivityCustomPeriod(new TimeSpan('2018-10-01 00:00:00', '2018-10-17 23:59:59'), $user);

		// Last 30 minutes earns work rise, 15:00-15:30
		$activityDay = $activityCustomPeriod->getActivityDay('2018-10-01');
		$this->assertEquals(7.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanBasicTime::class, ['activityTypeInternalName' => ACTIVITY_TYPE_WORK_HOUR_BANK_LEAVE_INTERNAL_NAME]));
		$this->assertEquals(1800, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));

		// Bank leave won't be earned from 15:00 onwards, no work rise
		$activityDay = $activityCustomPeriod->getActivityDay('2018-10-02');
		$this->assertEquals(7.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanBasicTime::class, ['activityTypeInternalName' => ACTIVITY_TYPE_WORK_HOUR_BANK_LEAVE_INTERNAL_NAME]));
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class));

		// 1 hour of bank leave earned after work during 15:00-16:00, one hour of work rise earned
		$activityDay = $activityCustomPeriod->getActivityDay('2018-10-04');
		$this->assertEquals(6.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanBasicTime::class, ['originatedFromArray' => [ACTIVITY_TYPE_WORK_ID]]));
		$this->assertEquals(3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanBasicTime::class, ['activityTypeInternalName' => ACTIVITY_TYPE_WORK_HOUR_BANK_LEAVE_INTERNAL_NAME]));
		$this->assertEquals(3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));

		// Bank leave won't be earned as daily obligation is earned before bank leave event starts. No work rise from it either
		$activityDay = $activityCustomPeriod->getActivityDay('2018-10-05');
		$this->assertEquals(7.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanBasicTime::class, ['originatedFromArray' => [ACTIVITY_TYPE_WORK_ID]]));
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanBasicTime::class, ['activityTypeInternalName' => ACTIVITY_TYPE_WORK_HOUR_BANK_LEAVE_INTERNAL_NAME]));
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class));

		// No bank leave and no work rise earned on weekend as there is no daily obligation set on weekend
		$activityDay = $activityCustomPeriod->getActivityDay('2018-10-06');
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanBasicTime::class, ['activityTypeInternalName' => ACTIVITY_TYPE_WORK_HOUR_BANK_LEAVE_INTERNAL_NAME]));
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class));
		$activityDay = $activityCustomPeriod->getActivityDay('2018-10-07');
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanBasicTime::class, ['activityTypeInternalName' => ACTIVITY_TYPE_WORK_HOUR_BANK_LEAVE_INTERNAL_NAME]));
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class));

		// No work rise earned as work rise earning from "Basic salary hours" is now set to no
		$activityDay = $activityCustomPeriod->getActivityDay('2018-10-08');
		$this->assertEquals(7.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanBasicTime::class, ['activityTypeInternalName' => ACTIVITY_TYPE_WORK_HOUR_BANK_LEAVE_INTERNAL_NAME]));
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));

		// Work rise is now set to be earned from balance leave, last 30 minutes earns work rise, 15:00-15:30
		$activityDay = $activityCustomPeriod->getActivityDay('2018-10-15');
		$this->assertEquals(7.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanBasicTime::class, ['originatedFromArray' => ACTIVITY_TYPE_INMADE_FREE_ID]));
		$this->assertEquals(1800, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));

		// Even though all balance leave hours are not compensated (as maximum negative amount has been reached), all of it can be compensated.
		// For this reason last 30 minutes earns work rise, 15:00-15:30 (even though no balance leave during that time).
		// This is a known limitation as actual accrual leave compensation is calculated AFTER work rises have been calculated.
		$activityDay = $activityCustomPeriod->getActivityDay('2018-10-16');
		$this->assertEquals(2.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanBasicTime::class, ['originatedFromArray' => ACTIVITY_TYPE_INMADE_FREE_ID]));
		$this->assertEquals(1800, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));

		// Tests that work rise is not earned when borrowed from work hour balance if there is no balance leave event
		$activityDay = $activityCustomPeriod->getActivityDay('2018-10-17');
		$this->assertEquals(7.5 * 3600, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanBasicTime::class, ['originatedFromArray' => ORIGINATED_BORROWED_FROM_ACCRUAL]));
		$this->assertEquals('2018-10-17 15:30:00', $activityDay->getTimeSpanCollection()->getLastTimeSpanOfType(TimeSpanBasicTime::class, ['originatedFromArray' => ORIGINATED_BORROWED_FROM_ACCRUAL])->getEndDateTime()); // Ends at time where it could potentially earn work rise
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanBasicTime::class, ['originatedFromArray' => ACTIVITY_TYPE_INMADE_FREE_ID]));
		$this->assertEquals(0, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
	}

	/**
	 * Tests that work rise is earned from assumed lunch that comes from a shift when work rise is earned from lunch hour event type.
	 *
	 * @link https://dev.azure.com/neptongroup/Nepton%20Dev/_workitems/edit/5855
	 * @return void
	 */
	public function testWorkRiseEarnedFromWorkShiftAssumedLunch(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Assumed Paid Lunch From Shift');
		$acp = new ActivityCustomPeriod(new TimeSpan('2017-08-07 00:00:00', '2017-08-07 23:59:59'), $user);

		$activityDay = $acp->getActivityDay('2017-08-07');
		$this->assertEquals(30 * 60, $activityDay->getTimeSpanCollection()->durationInSecondsByTimeSpanClassTypeWithInclusiveAndExclusiveParams(TimeSpanWorkRise::class, ['number' => 1]));
	}

	/**
	 * What are we testing: That time created due to rounding up can be excluded from work rises (if configured to do so)
	 * Why are we testing this: Customers may only want to compensate a work rise from "worked time" as opposed to paid time
	 * Expected outcome: The workRiseEarnedFromPositiveRounding setting is set to No so the work rise should not be earned
	 * for time that only exists due to rounding.
	 *
	 * @covers SalaryConceptWorkRises::process
	 * @link https://neptongroup.visualstudio.com/Nepton%20Dev/_workitems/edit/8841
	 */
	public function testWorkRiseEarnedFromPositiveRounding_RoundedTimeExcluded(): void {
		$user = $this->userRepository->selectTestUserByCustomerName('UNITTESTCUSTOMER Ignoring rounded time from reports and rises');
		$activityCustomPeriod = new ActivityCustomPeriod(new TimeSpan('2020-06-01 00:00:00', '2020-06-28 23:59:59'), $user);

		// First week is rounding events to nearest week...

		// Monday 01.06.2020: 8h is paid after rounding but only 7:41 of work rise should be earned because we are ignoring time created due to rounding in the earned work rise
		$collection = $activityCustomPeriod->getActivityDay('2020-06-01')->getTimeSpanCollection();
		self::assertEquals((8 * 3600) + (0 * 60), $collection->durationInSecondsByTimeSpanClassType(TimeSpanPaidTime::class));
		self::assertEquals((7 * 3600) + (41 * 60), $collection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['number'=>1]));

		// Tuesday 02.06.2020: 8h is paid after rounding but only 7:59 of work rise should be earned because we are ignoring time created due to rounding in the earned work rise
		$collection = $activityCustomPeriod->getActivityDay('2020-06-02')->getTimeSpanCollection();
		self::assertEquals((8 * 3600) + (0 * 60), $collection->durationInSecondsByTimeSpanClassType(TimeSpanPaidTime::class));
		self::assertEquals((7 * 3600) + (59 * 60), $collection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['number'=>1]));

		// Second week (first half) is rounding the event start time to nearest half hour

		// Monday 08.06.2020: Start time of 8:15 gets rounded up to 8:30. So no positively rounded time and work rise should be compensated same amount as regular time
		$collection = $activityCustomPeriod->getActivityDay('2020-06-08')->getTimeSpanCollection();
		self::assertEquals((7 * 3600) + (31 * 60), $collection->durationInSecondsByTimeSpanClassType(TimeSpanPaidTime::class));
		self::assertEquals((7 * 3600) + (31 * 60), $collection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['number'=>1]));

		// Tuesday 09.06.2020: Start time of 8:14 gets rounded to 8:00. That means the work rise will earn 14 minutes less than the normal paid time
		$collection = $activityCustomPeriod->getActivityDay('2020-06-09')->getTimeSpanCollection();
		self::assertEquals((8 * 3600) + (1 * 60), $collection->durationInSecondsByTimeSpanClassType(TimeSpanPaidTime::class));
		self::assertEquals((7 * 3600) + (47 * 60), $collection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['number'=>1]));

		// Second week of June (second half) is rounding the event end time to nearest half hour...

		// Thursday 11.06.2020: The end time of 16:01 gets rounded down to 16:00 so there should be no difference between basic pay and work rise as we only care about time rounded up
		$collection = $activityCustomPeriod->getActivityDay('2020-06-11')->getTimeSpanCollection();
		self::assertEquals((8 * 3600) + (0 * 60), $collection->durationInSecondsByTimeSpanClassType(TimeSpanPaidTime::class));
		self::assertEquals((8 * 3600) + (0 * 60), $collection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['number'=>1]));

		// Friday 12.06.2020: The end time of 15:58 gets rounded up by 2 mins so the work rise should be 2 mins less than the basic pay
		$collection = $activityCustomPeriod->getActivityDay('2020-06-12')->getTimeSpanCollection();
		self::assertEquals((8 * 3600) + (0 * 60), $collection->durationInSecondsByTimeSpanClassType(TimeSpanPaidTime::class));
		self::assertEquals((7 * 3600) + (58 * 60), $collection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['number'=>1]));

		// Third week of June is rounding the total hours for the day...

		// Monday 15.06.2020: Rounding down so paid time and earned work rise should be the same
		$collection = $activityCustomPeriod->getActivityDay('2020-06-15')->getTimeSpanCollection();
		self::assertEquals((8 * 3600) + (0 * 60), $collection->durationInSecondsByTimeSpanClassType(TimeSpanPaidTime::class));
		self::assertEquals((8 * 3600) + (0 * 60), $collection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['number'=>1]));

		// Tuesday 16.06.2020: Time has been rounded up by 11 minutes which should not be earned towards work rise
		$collection = $activityCustomPeriod->getActivityDay('2020-06-16')->getTimeSpanCollection();
		self::assertEquals((8 * 3600) + (0 * 60), $collection->durationInSecondsByTimeSpanClassType(TimeSpanPaidTime::class));
		self::assertEquals((7 * 3600) + (49 * 60), $collection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['number'=>1]));

		// Fourth week of June is rounding overtime total to the nearest 30 minutes...

		// Monday 22.06.2020: 14 mins of overtime are rounded down to 0. So no overtime or work rise due above 8h
		$collection = $activityCustomPeriod->getActivityDay('2020-06-22')->getTimeSpanCollection();
		self::assertEquals((8 * 3600) + (0 * 60), $collection->durationInSecondsByTimeSpanClassType(TimeSpanPaidTime::class));
		self::assertEquals((8 * 3600) + (0 * 60), $collection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['number'=>1]));

		// Tuesday 23.06.2020: 15 minutes of overtime is rounded to 30 mins. 30 mins overtime should be paid, but work rise only earned for time worked
		$collection = $activityCustomPeriod->getActivityDay('2020-06-23')->getTimeSpanCollection();
		self::assertEquals((8 * 3600) + (30 * 60), $collection->durationInSecondsByTimeSpanClassType(TimeSpanPaidTime::class));
		self::assertEquals((8 * 3600) + (15 * 60), $collection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, ['number'=>1]));
	}
}
