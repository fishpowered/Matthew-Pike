<?php
namespace Tyoaika\activityReporting\salaryEngine\salaryConcepts;

use ActivityType;
use BadMethodCallException;
use BadRequestException;
use constantFactory;
use EventTypeNotFoundException;
use FunctionalityNotImplementedYetException;
use InvalidArgumentException;
use LogicException;
use PDOException;
use QueryException;
use RuntimeException;
use SdsqlQueryException;
use SecurityException;
use SetupException;
use TimeSpanAssumedLunchHour;
use TimeSpanBasicTime;
use TimeSpanLunchHour;
use TimeSpanMarkedActivityType;
use TimeSpanOvertimeAdditionalWork;
use TimeSpanOvertimeDaily100;
use TimeSpanOvertimeDaily50;
use TimeSpanOvertimeSunday100;
use TimeSpanOvertimeSunday50;
use TimeSpanOvertimeWeekly100;
use TimeSpanOvertimeWeekly50;
use TimeSpanReservedBasicTimeToBePaidByAccrual;
use TimeSpanSalaryRenderingSection;
use TimeSpanUnpaidOvertime;
use TimeSpanWorkRise;
use TimeSpanWorkRiseActive;
use TimeSpanWorkRiseNegativePercentagePartByRiseNumber;
use TimeSpanWorkRisePercentagePartByRiseNumber;
use TimeSpanYearLeave;
use Tyoaika\activityReporting\classes\{Activity, ActivityPayTarget, ActivityTypeRepository};
use Tyoaika\activityReporting\salaryEngine\UserSalaryDay;
use Tyoaika\common\classes\{Filter, RoundSeconds, TimeSpan, TimeSpanCollection, TimeSpanQueryCondition};
use UserInputException;
use function array_key_exists;
use function array_merge;
use function count;
use function floor;
use function ksort;
use function number_format;
use function round;
use const ABSENCE_COMPENSATION_TYPE_UNPAID;
use const ACTIVITY_TYPE_LUNCH_HOUR_ID;
use const APPROVED_BY_SYSTEM;
use const DEFAULT_OVERTIME_ADDITIONAL_PERCENTAGE;
use const ORIGINATED_ADDITIONAL_WORK_FORCED_TO_SALARY_TYPE_BY_WORK_RISE;
use const ORIGINATED_BASIC_TIME_FORCED_TO_SALARY_TYPE_BY_WORK_RISE;
use const ORIGINATED_BORROWED_FROM_ACCRUAL;
use const ORIGINATED_HOUR_TO_HOUR_OVERTIME;
use const ORIGINATED_LUNCH_HOUR_TYPE;
use const ORIGINATED_MINIMUM_WORK_TIME_COMPENSATION;
use const ORIGINATED_NON_ADDITIONAL_OVERTIME_FORCED_TO_SALARY_TYPE_BY_WORK_RISE;
use const ORIGINATED_TIME_CREATED_DUE_TO_ROUNDING_UP_OR_MULTIPLYING;
use const ORIGINATED_UNPAID_OVERTIME_FORCED_TO_SALARY_TYPE_BY_WORK_RISE;
use const SICKLEAVE_COMPENSATION_TYPE_UNPAID;
use const SORT_NATURAL;

/**
 * The system allows for configuration of different "work rises" (configured periods that affect the
 * rate of pay e.g. compensation due to working late at night, Sundays etc).
 *
 * This SalaryConcept creates the TimeSpan's required to define how a user has overlapped into a
 * work rise period (for a particular work rise) e.g. If work rise #3 has an active period of
 * 1800-2400 on a monday, and the user works 0900-1900 on a monday then they have done one hour
 * at the rate set by the work time, so a one hour long "TimeSpanWorkRise" is created for the
 * period of 1800-1900 and "TimeSpanWorkRiseActive" is created for the time period they could
 * have earned the compensation.
 *
 * There are two potential work rise periods per day to allow for morning and evening rates, and
 * each period can be configured to start/end on the  current day, previous day or next day.
 * This is to support overnight shifts. For example if a shift starts at 10pm you may want to
 * allow night rise from 10pm to 6AM the next day but if the shift starts at 5am on the next
 * day then you may not want the night rise to be earned between 5 and 6am, these
 * current/next/previous day settings allow you to configure that as the settings are only
 * picked up on the days the shifts belong to.
 *
 * The shifts also pick up the work rise active settings/spans for every day the shift overlaps,
 * so if you want night rise to be earned between 10PM - 6AM regardless of which day/time they
 * start you can just ensure the settings are 00-06 and 22-24 on each day and not worry about
 * setting the times to next/prev day
 *
 * @package Tyoaika\activityReporting\salaryEngine\salaryConcepts
 */
class SalaryConceptWorkRises extends SalaryConcept {

	/**
	 * If a work rise is paid to an accrual target, either the target can be set explicitly or it can inherit from the event settings + setting hierarchy
	 */
	public const WORK_RISE_PAY_TARGET_INHERITED_FROM_OVERTIME_BASE_PART = 'inheritOvertimeBasePartTarget';

	/**
	 * If a work rise is paid to an accrual target, either the target can be set explicitly or it can inherit from the event settings + setting hierarchy
	 */
	public const WORK_RISE_PAY_TARGET_INHERITED_FROM_OVERTIME_INCREMENT_PART = 'inheritOvertimeIncrementedPartTarget';

	/**
	 * If a work rise has fixed compensation, the fixed compensation is only earned once for the whole day no matter how many shifts/events may trigger it
	 */
	public const WORK_RISE_FIXED_COMPENSATION_PAY_METHOD_PAY_ONCE_PER_DAY = 'pay_once_per_day';

	/**
	 * If a work rise has fixed compensation, the fixed compensation is earned once for every event that may trigger it
	 */
	public const WORK_RISE_FIXED_COMPENSATION_PAY_METHOD_PAY_PER_EVENT_PER_DAY = 'pay_per_event_per_day';

	/**
	 * @var int
	 */
	protected $customerId;

	/**
	 * @var UserSalaryDay
	 */
	protected $userSalaryDay;

	/**
	 * @var string[]
	 */
	protected $paidAndUnpaidSpanTypes;

	/**
	 * @var bool
	 */
	protected $overtimePaidWithFixedSalaryInsteadOfPercentages;

	/**
	 * @param UserSalaryDay $userSalaryDay
	 *
	 * @throws SetupException
	 */
	public function __construct(UserSalaryDay $userSalaryDay) {
		$this->userSalaryDay = $userSalaryDay;
		$this->customerId = $userSalaryDay->getUser()->getCustomerId();
		$this->paidAndUnpaidSpanTypes = array_merge(
			constantFactory::getConstant_SALARY_CONCEPT_TIME_SPAN_TYPES(true),
			[TimeSpanReservedBasicTimeToBePaidByAccrual::class]
		);
		parent::__construct($userSalaryDay);
	}

	/**
	 * Generate the TimeSpanWorkRiseActive and TimeSpanWorkRise spans (see class phpdoc for more info)
	 *
	 * @return void
	 */
	protected function process(): void {
		// Build the TimeSpan's to represent when the work rise is active (we need to ask the previous and next days for their settings in case they overlap the current day)
		$workRiseActiveSpanCollection = new TimeSpanCollection();
		$userCalendarDays = $this->userSalaryDay->getUserCalendarDaysThatTimeSpanCollectionOverlaps();
		foreach ($userCalendarDays as $userCalendarDay) {
			$workRiseActiveCollection = $userCalendarDay->getWorkRiseActiveTimeSpanCollection($this->userSalaryDay);
			$workRiseActiveSpanCollection->mergeAnotherTimeSpanCollection($workRiseActiveCollection);
		}

		// Earn work rise based on time overlapping with active work rises
		if ($workRiseActiveSpanCollection->hasTimeSpans()) {
			$this->overtimePaidWithFixedSalaryInsteadOfPercentages = ('Y' === $this->userSalaryDay->getSettingValue('overtimePaidWithFixedSalaryInsteadOfPercentages', 1));
			$workRiseActiveSpanCollectionsPerRiseNumber = $this->getWorkRiseActiveSpanCollectionPerRiseNumber($workRiseActiveSpanCollection);
			foreach ($workRiseActiveSpanCollectionsPerRiseNumber as $workRiseNumber => $workRiseActiveCollectionForRiseNumber) {

				// Should work rise be earned based on the time of the shift
				if ($this->shouldWorkRiseBeEarnedBasedOnTheTypeOfTheShift($workRiseNumber)) {

					// Fetch which salary+marked TimeSpan's can earn the work rise based on the settings (IMPORTANT: we PULL them from the UserSalaryDay collection so they can easily be manipulated before reinserting)
					$salaryAndMarkedSpansThatCanEarnWorkRise = $this->getTimeSpansThatCanEarnWorkRise($workRiseNumber, $workRiseActiveCollectionForRiseNumber);

					if ($salaryAndMarkedSpansThatCanEarnWorkRise->hasTimeSpans()) {

						// Generate our earned work rise spans for the time the work overlaps the active work rise period
						$earnedWorkRiseCollectionForRiseNumber = $this->calculateEarnedWorkRiseTimeSpanCollection($workRiseNumber, $workRiseActiveCollectionForRiseNumber, $salaryAndMarkedSpansThatCanEarnWorkRise);

						// Set pay targets for earned work rise
						$this->setPayTargetsForEarnedWorkRiseCollection($earnedWorkRiseCollectionForRiseNumber);

						// Add everything created back to the collection
						$this->userSalaryDay->getTimeSpanCollection()->add($earnedWorkRiseCollectionForRiseNumber->getTimeSpanArray(), false);
					}

					// Add our original salary+marked TimeSpan's back into the UserSalaryDay now we are finished with them
					$this->userSalaryDay->getTimeSpanCollection()->add($salaryAndMarkedSpansThatCanEarnWorkRise->getTimeSpanArray(), false);
				}
				$this->userSalaryDay->getTimeSpanCollection()->add($workRiseActiveCollectionForRiseNumber->getTimeSpanArray(), false);
			}

			// Some work rises can only be earned if others haven't. We need to sort that out before we can proceed...
			$this->removeWorkRisesThatShouldNotBeEarnedDueToEarningAnotherRise();

			// Now we know for sure which can be earned, we can do any rounding, forcing of salary etc...
			foreach ($workRiseActiveSpanCollectionsPerRiseNumber as $workRiseNumber => $workRiseActiveCollectionForRiseNumber) {
				// Fetch which salary+marked TimeSpan's can earn the work rise based on the settings (IMPORTANT: we PULL them from the UserSalaryDay collection so they can easily be manipulated before reinserting)
				$salaryAndMarkedSpansThatCanEarnWorkRise = $this->getTimeSpansThatCanEarnWorkRise($workRiseNumber, $workRiseActiveCollectionForRiseNumber);

				if ($salaryAndMarkedSpansThatCanEarnWorkRise->hasTimeSpans()) {
					$timeSpanCollection = $this->userSalaryDay->getTimeSpanCollection();
					$earnedWorkRiseCollectionForRiseNumber = $timeSpanCollection->getTimeSpanCollectionByTimeSpanClassName(TimeSpanWorkRise::class, ['number' => $workRiseNumber]);
					// Force any underlying salary to a particular percentage if the settings are configured to do so
					$this->forceTimeThatCanEarnWorkRiseToOvertimePercentage($workRiseNumber, $earnedWorkRiseCollectionForRiseNumber, $salaryAndMarkedSpansThatCanEarnWorkRise);

					// Round the earned work rise
					$this->roundEarnedWorkRiseCollection((int) $workRiseNumber, $earnedWorkRiseCollectionForRiseNumber, $timeSpanCollection);

					// Adjust based on earning percentage
					$workRisePercentagePartCollection = $this->earnWorkRisePercentagePart($workRiseNumber, $earnedWorkRiseCollectionForRiseNumber);

					$this->userSalaryDay->getTimeSpanCollection()->add($workRisePercentagePartCollection->getTimeSpanArray(), false);
				}
				// Add our original salary+marked TimeSpan's back into the UserSalaryDay now we are finished with them
				$this->userSalaryDay->getTimeSpanCollection()->add($salaryAndMarkedSpansThatCanEarnWorkRise->getTimeSpanArray(), false);
			}
		}

		// Remove any work rise spans that are zero length due to rounding or whatever
		$this->userSalaryDay->getTimeSpanCollection()->removeEmptyTimeSpans(TimeSpanWorkRise::class);
	}

	/**
	 * Normally the work rise pay target is set explicitly but it can also be inherited from the event/settings, in which case we need to check the event
	 *
	 * @param TimeSpanCollection $earnedWorkRiseCollectionForRiseNumber
	 *
	 * @return void
	 */
	protected function setPayTargetsForEarnedWorkRiseCollection(TimeSpanCollection $earnedWorkRiseCollectionForRiseNumber): void {
		foreach ($earnedWorkRiseCollectionForRiseNumber->getTimeSpanArray() as $index => $earnedWorkRiseSpan) {
			/* @var $earnedWorkRiseSpan TimeSpanWorkRise */
			if (static::WORK_RISE_PAY_TARGET_INHERITED_FROM_OVERTIME_BASE_PART === $earnedWorkRiseSpan->workRiseAccrualTarget) {
				$workRiseEarnedFromActivity = $earnedWorkRiseSpan->getDerivedFromActivity();
				if ($workRiseEarnedFromActivity instanceof Activity) {
					$earnedWorkRiseSpan->workRiseAccrualTarget = $workRiseEarnedFromActivity->getOvertimeBasePartTargetAndCalculateFromSettingsIfSetToAutomatic($this->userSalaryDay->getSettingValueProxy(), $this->userSalaryDay->getUser());
				} else {
					$earnedWorkRiseSpan->workRiseAccrualTarget = ActivityPayTarget::SALARY;
				}
			} elseif (static::WORK_RISE_PAY_TARGET_INHERITED_FROM_OVERTIME_INCREMENT_PART === $earnedWorkRiseSpan->workRiseAccrualTarget) {
				$workRiseEarnedFromActivity = $earnedWorkRiseSpan->getDerivedFromActivity();
				if ($workRiseEarnedFromActivity instanceof Activity) {
					$earnedWorkRiseSpan->workRiseAccrualTarget = $workRiseEarnedFromActivity->getOvertimeIncrementPartTargetAndCalculateFromSettingsIfSetToAutomatic($this->userSalaryDay->getSettingValueProxy(), $this->userSalaryDay->getUser());
				} else {
					$earnedWorkRiseSpan->workRiseAccrualTarget = ActivityPayTarget::SALARY;
				}
			}

			// Remove any that have been set to uncompensated
			if (ActivityPayTarget::UNCOMPENSATED === $earnedWorkRiseSpan->workRiseAccrualTarget) {
				$earnedWorkRiseCollectionForRiseNumber->removeTimeSpanByIndex($index);
			}
		}
	}

	/**
	 * Round earned work rise collection based on settings.
	 *
	 * @param int                $workRiseNumber
	 * @param TimeSpanCollection $earnedWorkRiseCollection Time span collection containing all earned spans for work rise
	 * @param TimeSpanCollection $timeSpanCollection Time span collection to add any new time spans to
	 *
	 * @return void
	 * @throws SetupException
	 */
	protected function roundEarnedWorkRiseCollection(int $workRiseNumber, TimeSpanCollection $earnedWorkRiseCollection, TimeSpanCollection $timeSpanCollection): void {
		$roundingMode = $this->userSalaryDay->getSettingValue('workRiseRoundingMode', $workRiseNumber);
		if ($roundingMode && RoundSeconds::ROUNDING_METHOD_NONE !== $roundingMode) {
			$roundingIntervalInSeconds = 60 * (int) $this->userSalaryDay->getSettingValue('workRiseRoundingInterval', $workRiseNumber, 0);
			$roundingThresholdInSeconds = 60 * (int) $this->userSalaryDay->getSettingValue('workRiseRoundingThreshold', $workRiseNumber, 0);
			$earnedWorkRiseCollection->roundTotalDuration($roundingMode, $roundingIntervalInSeconds, $roundingThresholdInSeconds, null, null, $timeSpanCollection, true, ORIGINATED_TIME_CREATED_DUE_TO_ROUNDING_UP_OR_MULTIPLYING);
		}
	}

	/**
	 * Work out when the work rise is earned based on the the times the work rise is active and the times when the user was working
	 *
	 * @param int                $workRiseNumber
	 * @param TimeSpanCollection $workRiseActiveSpanCollectionForRiseNumber spans representing WHEN the work rise can be earned
	 * @param TimeSpanCollection $timeSpanCollectionThatCanEarnWorkRise spans representing the time on the day that can earn the work rise
	 *
	 * @return TimeSpanCollection
	 * @throws FunctionalityNotImplementedYetException
	 * @throws InvalidArgumentException
	 * @throws LogicException
	 * @throws UserInputException
	 */
	protected function calculateEarnedWorkRiseTimeSpanCollection(int $workRiseNumber, TimeSpanCollection $workRiseActiveSpanCollectionForRiseNumber, TimeSpanCollection $timeSpanCollectionThatCanEarnWorkRise): TimeSpanCollection {

		// Find overlaps between the work rise active time spans and the worked time to calculate when work rise should be earned
		$negationResult = null;
		$earnedWorkRiseCollection = $workRiseActiveSpanCollectionForRiseNumber->findOverlapsFromOtherTimeSpanCollection($timeSpanCollectionThatCanEarnWorkRise, TimeSpanWorkRise::class, $negationResult, null, true, $this->overtimePaidWithFixedSalaryInsteadOfPercentages);
		$earnedWorkRiseDurationInSeconds = $earnedWorkRiseCollection->durationInSeconds();

		// If the minimum earning threshold hasn't been exceeded we don't want to pay anything
		$minimumEarningThresholdInSeconds = 60 * (int) $this->userSalaryDay->getSettingValue('workRiseMinimumEarningThreshold', $workRiseNumber);
		if ($minimumEarningThresholdInSeconds > 0 && $earnedWorkRiseDurationInSeconds < $minimumEarningThresholdInSeconds) {
			return new TimeSpanCollection();
		}

		$maximumEarningThresholdInSeconds = 60 * (int) $this->userSalaryDay->getSettingValue('workRiseMaximumEarningThreshold', $workRiseNumber);
		if ($maximumEarningThresholdInSeconds > 0 && $earnedWorkRiseDurationInSeconds > $maximumEarningThresholdInSeconds) {
			return new TimeSpanCollection();
		}

		// If there is a minimum compensation amount, we need to extend what we have already earned
		$minCompensationAmountInSeconds = 60 * (int) $this->userSalaryDay->getSettingValue('workRiseMinAmountCompensated', $workRiseNumber);
		if ($minCompensationAmountInSeconds > 0 && $earnedWorkRiseDurationInSeconds > 0 && $earnedWorkRiseDurationInSeconds < $minCompensationAmountInSeconds) {
			$earnedWorkRiseCollection->addOrRemoveSecondsToEndOfTimeSpanType($minCompensationAmountInSeconds - $earnedWorkRiseDurationInSeconds, TimeSpanWorkRise::class, [], [], ORIGINATED_TIME_CREATED_DUE_TO_ROUNDING_UP_OR_MULTIPLYING);
			return $earnedWorkRiseCollection;
		}

		// If there is a maximum compensation amount, we need to limit what we have already earned
		$maxCompensationAmountInSeconds = 60 * (int) $this->userSalaryDay->getSettingValue('workRiseMaxAmountCompensated', $workRiseNumber);
		if ($maxCompensationAmountInSeconds > 0 && $earnedWorkRiseDurationInSeconds > 0 && $earnedWorkRiseDurationInSeconds > $maxCompensationAmountInSeconds) {
			$earnedWorkRiseCollection->addOrRemoveSecondsToEndOfTimeSpanType($maxCompensationAmountInSeconds - $earnedWorkRiseDurationInSeconds, TimeSpanWorkRise::class, [], [], null);
			return $earnedWorkRiseCollection;
		}

		// If there is a fixed compensation amount, this should be earned in place of what we have
		$fixedCompensationAmountInSeconds = 60 * (int) $this->userSalaryDay->getSettingValue('workRiseFixedCompensationAmount', $workRiseNumber);
		if ($earnedWorkRiseDurationInSeconds > 0 && $fixedCompensationAmountInSeconds > 0) {
			$fixedCompensationMethod = $this->userSalaryDay->getSettingValue('workRiseFixedCompensationPayMethod', $workRiseNumber, static::WORK_RISE_FIXED_COMPENSATION_PAY_METHOD_PAY_ONCE_PER_DAY);
			if (static::WORK_RISE_FIXED_COMPENSATION_PAY_METHOD_PAY_ONCE_PER_DAY === $fixedCompensationMethod) {
				$earnedWorkRiseCollection->addOrRemoveSecondsToEndOfTimeSpanType($fixedCompensationAmountInSeconds - $earnedWorkRiseDurationInSeconds, TimeSpanWorkRise::class, [], [], ORIGINATED_TIME_CREATED_DUE_TO_ROUNDING_UP_OR_MULTIPLYING);
			} elseif (static::WORK_RISE_FIXED_COMPENSATION_PAY_METHOD_PAY_PER_EVENT_PER_DAY === $fixedCompensationMethod) {
				$alreadyPaidActivities = [];
				foreach ($earnedWorkRiseCollection->getTimeSpanArray() as $index => $timeSpan) {
					$derivedActivity = $timeSpan->getDerivedFromActivity();
					if ($derivedActivity) {
						$activityId = $derivedActivity->getIdOrUniqueHashIfIdIsUnavailable();
						if (!array_key_exists($activityId, $alreadyPaidActivities)) {
							$requiredProperties = ['derivedFromActivityId' => $activityId];
							$earnedFromActivityOverlappingRise = $earnedWorkRiseCollection->durationInSecondsByTimeSpanClassType(TimeSpanWorkRise::class, $requiredProperties);
							$earnedWorkRiseCollection->addOrRemoveSecondsToEndOfTimeSpanType($fixedCompensationAmountInSeconds - $earnedFromActivityOverlappingRise, TimeSpanWorkRise::class, $requiredProperties, [], ORIGINATED_TIME_CREATED_DUE_TO_ROUNDING_UP_OR_MULTIPLYING);
							$alreadyPaidActivities[$activityId] = true;
						}
					}
				}
			}
			return $earnedWorkRiseCollection;
		}

		$earnedWorkRiseCollection->removeOverlappingParts();
		return $earnedWorkRiseCollection;
	}

	/**
	 * Not all activity types and compensations should earn the work rise, this will return only the spans that can actually earn the current work rise
	 *
	 * @param int                $workRiseNumber
	 * @param TimeSpanCollection $workRiseActiveCollectionForRiseNumber Times the current work rise is active
	 *
	 * @return TimeSpanCollection
	 * @throws BadRequestException
	 * @throws FunctionalityNotImplementedYetException
	 * @throws InvalidArgumentException
	 * @throws QueryException
	 * @throws SetupException
	 */
	protected function getTimeSpansThatCanEarnWorkRise(int $workRiseNumber, TimeSpanCollection $workRiseActiveCollectionForRiseNumber): TimeSpanCollection {
		$activityPayTargetRepositoryProxy = $this->userSalaryDay->getUserSalaryEngine()->getActivityPayTargetRepositoryProxy();
		$payTargets = $activityPayTargetRepositoryProxy->getAll($this->userSalaryDay->getUser()->getCustomerId());

		/* @var $activityTypesThatCanEarnWorkRise ActivityType[] */
		$activityTypesThatCanEarnWorkRise = [];
		$salaryDayTimeSpanCollection = $this->userSalaryDay->getTimeSpanCollection();

		// Find which activity types do we have on the day that can earn this work rise
		$activityTypesInDay = $this->getActivityTypesInCollection($salaryDayTimeSpanCollection);
		if ($salaryDayTimeSpanCollection->hasTimeSpansOfType(TimeSpanAssumedLunchHour::class)) {
			// Lunch doesn't always have a type if it's assumed so we have to handle it separately
			$activityTypesInDay[ACTIVITY_TYPE_LUNCH_HOUR_ID] = ActivityTypeRepository::getInstance()->getActivityTypeById(ACTIVITY_TYPE_LUNCH_HOUR_ID, $this->customerId); // @todo remove singleton and inject dependency
		}
		foreach ($activityTypesInDay as $activityTypeId => $activityType) {
			if ('Y' === $this->userSalaryDay->getSettingValue('workRiseEarnedFromActivityTypeId', [$workRiseNumber, $activityTypeId])) {
				$activityTypesThatCanEarnWorkRise[] = $activityType;
			}
		}

		$returnCollection = new TimeSpanCollection();
		if (count($activityTypesThatCanEarnWorkRise) > 0) {
			// Check settings for which sort of time should be included/excluded
			$originatedFromArrayBlockingParams = [ORIGINATED_BORROWED_FROM_ACCRUAL, ORIGINATED_MINIMUM_WORK_TIME_COMPENSATION];

			// Not all activity types should be queried using the same span types and originatedFromArray so we need to separate types that should query obligated/paid time and time as marked
			$activityTypeIdsThatEarnWorkRiseFromObligatedSpans = [];
			$activityTypeIdsThatEarnWorkRiseFromMarkedSpans = [];
			foreach ($activityTypesThatCanEarnWorkRise as $activityTypeThatCanEarnWorkRise) {
				if ($activityTypeThatCanEarnWorkRise->getIsLunchType()) {
					$activityTypeIdsThatEarnWorkRiseFromMarkedSpans[] = $activityTypeThatCanEarnWorkRise->getId();
					$activityTypeIdsThatEarnWorkRiseFromMarkedSpans[] = ORIGINATED_LUNCH_HOUR_TYPE;
				} elseif ($activityTypeThatCanEarnWorkRise->getCanFulfilObligation() || $activityTypeThatCanEarnWorkRise->isAgreedDailyOvertime() || $activityTypeThatCanEarnWorkRise->isAgreedDailyOrWeeklyOvertime()) {
					$activityTypeIdsThatEarnWorkRiseFromObligatedSpans[] = (int) $activityTypeThatCanEarnWorkRise->getId();
				} elseif ($activityTypeThatCanEarnWorkRise->getCanBeReportedByDuration()) {
					$activityTypeIdsThatEarnWorkRiseFromMarkedSpans[] = $activityTypeThatCanEarnWorkRise->getId();
				}
			}

			$earnFromTimeCreatedDueToRoundingUp = 'N' !== $this->userSalaryDay->getSettingValue('workRiseEarnedFromPositiveRounding', $workRiseNumber); // Default to Yes so we don't break how old work rises work

			// Following query equates to:
			// SELECT time spans
			// WHERE (
			//	 (span is paid/unpaid obligated time AND is of specified activity type
			// 		(basic time targeting checks OR overtime targeting checks)
			// 		AND not borrowed from balance/work time shortening leave compensation
			//		AND not unpaid sick leave
			//		AND not unpaid absence
			//	 )
			//   OR $mainOrCondition (span is marked type AND of specified activity type)
			// )
			$mainOrCondition = new TimeSpanQueryCondition(TimeSpanQueryCondition::CONDITION_TYPE_OR);
			if (count($activityTypeIdsThatEarnWorkRiseFromMarkedSpans) > 0) {
				$markedSpanTypes = [TimeSpanMarkedActivityType::class, TimeSpanLunchHour::class];
				$subAndCondition = new TimeSpanQueryCondition(TimeSpanQueryCondition::CONDITION_TYPE_AND);
				$subAndCondition->conditionActivityTypeIdIn($activityTypeIdsThatEarnWorkRiseFromMarkedSpans);
				$subAndCondition->conditionClassNameIn($markedSpanTypes);
				if (!$earnFromTimeCreatedDueToRoundingUp) {
					$subAndCondition->conditionDoesNotContain('originatedFromArray', ORIGINATED_TIME_CREATED_DUE_TO_ROUNDING_UP_OR_MULTIPLYING);
				}
				$mainOrCondition->conditionClass($subAndCondition);
			}

			$spansThatCanEarnWorkRiseQuery = $salaryDayTimeSpanCollection->newQuery();
			$spansThatCanEarnWorkRiseQuery->condition($mainOrCondition);

			if (count($activityTypeIdsThatEarnWorkRiseFromObligatedSpans) > 0) {
				$paidAndUnpaidTypes = $this->paidAndUnpaidSpanTypes;
				$earningCondition = $salaryDayTimeSpanCollection->newCondition();
				$earningCondition->conditionClassNameIn($paidAndUnpaidTypes);
				$earningCondition->conditionActivityTypeIdIn($activityTypeIdsThatEarnWorkRiseFromObligatedSpans);
				if (!$earnFromTimeCreatedDueToRoundingUp) {
					$earningCondition->conditionDoesNotContain('originatedFromArray', ORIGINATED_TIME_CREATED_DUE_TO_ROUNDING_UP_OR_MULTIPLYING);
				}
				$earningCondition->conditionDoesNotContain('originatedFromArray', $originatedFromArrayBlockingParams)
					->conditionValue('compensationType', SICKLEAVE_COMPENSATION_TYPE_UNPAID, '!=')
					->conditionValue('absenceCompensationTypeId', ABSENCE_COMPENSATION_TYPE_UNPAID, '!=');

				/* "Compensation types that earn work rise" - compensation types default to not included unless setting is set to Yes */

				$orCanEarnCompensationType = $salaryDayTimeSpanCollection->newCondition(TimeSpanQueryCondition::CONDITION_TYPE_OR);

				// Basic salary and "unpaid" time
				if ('Y' === $this->userSalaryDay->getSettingValue('workRisesEarnedFromBasicSalaryWork', $workRiseNumber)) {
					$earningConditionBasicSalaryWork = $salaryDayTimeSpanCollection->newCondition();
					$earningConditionBasicSalaryWork->conditionClassNameIn([TimeSpanBasicTime::class, TimeSpanReservedBasicTimeToBePaidByAccrual::class]);
					$earningConditionBasicSalaryWork->conditionDoesNotContain('originatedFromArray', ORIGINATED_HOUR_TO_HOUR_OVERTIME);
					$orCanEarnCompensationType->conditionClass($earningConditionBasicSalaryWork);
				}

				// Hour to hour overtime
				foreach ($payTargets as $payTarget) {
					$workRiseEarnedFromPayTarget = ('Y' === $this->userSalaryDay->getSettingValue('workRisesEarnedFromHourToHourOvertime', [$workRiseNumber, $payTarget->getInternalName()]));
					if ($workRiseEarnedFromPayTarget && ActivityPayTarget::UNCOMPENSATED === $payTarget->getInternalName()) {
						$orCanEarnCompensationType->conditionClassNameIn(TimeSpanUnpaidOvertime::class);
					} elseif ($workRiseEarnedFromPayTarget) {
						$earningConditionBasicSalaryOvertime = $salaryDayTimeSpanCollection->newCondition();
						$earningConditionBasicSalaryOvertime->conditionContains('originatedFromArray', ORIGINATED_HOUR_TO_HOUR_OVERTIME);
						$earningConditionBasicSalaryOvertime->conditionValue('basePartTarget', $payTarget->getInternalName());
						$orCanEarnCompensationType->conditionClass($earningConditionBasicSalaryOvertime);
					}
				}

				// Overtime
				$includeOvertimeType = ('Y' === $this->userSalaryDay->getSettingValue('workRisesEarnedFromAdditionalWork', $workRiseNumber));
				if ($includeOvertimeType) {
					$orCanEarnCompensationType->conditionClassNameIn(TimeSpanOvertimeAdditionalWork::class);
				}
				$includeOvertimeType = ('Y' === $this->userSalaryDay->getSettingValue('workRisesEarnedFromOvertimeDaily50', $workRiseNumber));
				if ($includeOvertimeType) {
					$orCanEarnCompensationType->conditionClassNameIn(TimeSpanOvertimeDaily50::class);
				}
				$includeOvertimeType = ('Y' === $this->userSalaryDay->getSettingValue('workRisesEarnedFromOvertimeDaily100', $workRiseNumber));
				if ($includeOvertimeType) {
					$orCanEarnCompensationType->conditionClassNameIn(TimeSpanOvertimeDaily100::class);
				}
				$includeOvertimeType = ('Y' === $this->userSalaryDay->getSettingValue('workRisesEarnedFromOvertimeWeekly50', $workRiseNumber));
				if ($includeOvertimeType) {
					$orCanEarnCompensationType->conditionClassNameIn(TimeSpanOvertimeWeekly50::class);
				}
				$includeOvertimeType = ('Y' === $this->userSalaryDay->getSettingValue('workRisesEarnedFromOvertimeWeekly100', $workRiseNumber));
				if ($includeOvertimeType) {
					$orCanEarnCompensationType->conditionClassNameIn(TimeSpanOvertimeWeekly100::class);
				}
				$includeOvertimeType = ('Y' === $this->userSalaryDay->getSettingValue('workRisesEarnedFromOvertimeSunday50', $workRiseNumber));
				if ($includeOvertimeType) {
					$orCanEarnCompensationType->conditionClassNameIn(TimeSpanOvertimeSunday50::class);
				}
				$includeOvertimeType = ('Y' === $this->userSalaryDay->getSettingValue('workRisesEarnedFromOvertimeSunday100', $workRiseNumber));
				if ($includeOvertimeType) {
					$orCanEarnCompensationType->conditionClassNameIn(TimeSpanOvertimeSunday100::class);
				}
				$orCanEarnCompensationType->conditionClassNameIn(TimeSpanYearLeave::class); // Year leave spans should always be included as they can be disabled using the activity type check

				$earningCondition->conditionClass($orCanEarnCompensationType);
				$mainOrCondition->conditionClass($earningCondition);
			}

			$removeFoundSpans = true;
			$spansThatCanEarnWorkRise = $spansThatCanEarnWorkRiseQuery->execute($removeFoundSpans);
			//$returnCollection->removeOverlappingParts(); THIS WILL BREAK MANUAL OVERTIME THAT HAS BOTH SALARY AND INMADE SET

			// If this setting is on we only want to keep time spans whose parent activity *BEGINS* in the work rise active period
			if ('Y' === $this->userSalaryDay->getSettingValue('workRiseOnlyEarnedIfEventStartInValidityPeriod', $workRiseNumber)) {
				foreach ($spansThatCanEarnWorkRise as $index => $timeSpan) {
					$activity = $timeSpan->getDerivedFromActivity();
					if (!$activity || false === $workRiseActiveCollectionForRiseNumber->containsTimeStamp($activity->getBeginTimeStamp(), null, false, true)) {
						$salaryDayTimeSpanCollection->add($spansThatCanEarnWorkRise[$index], true);
						unset($spansThatCanEarnWorkRise[$index]);
					}
				}
			}
			$returnCollection->setTimeSpanArray($spansThatCanEarnWorkRise);
		}
		return $returnCollection;
	}

	/**
	 * @param TimeSpanCollection $workRiseActiveSpanCollection
	 * @return TimeSpanCollection[]
	 */
	protected function getWorkRiseActiveSpanCollectionPerRiseNumber(TimeSpanCollection $workRiseActiveSpanCollection): array {
		/* @var $workRiseActiveSpanCollectionPerRiseNumber TimeSpanCollection[] */
		$workRiseActiveSpanCollectionPerRiseNumber = [];
		foreach ($workRiseActiveSpanCollection->getTimeSpanArray() as $workRiseActiveSpan) {
			/* @var $workRiseActiveSpan TimeSpanWorkRiseActive */
			$workRiseNumber = (int) $workRiseActiveSpan->number;
			if (!isset($workRiseActiveSpanCollectionPerRiseNumber[$workRiseNumber])) {
				$workRiseActiveSpanCollectionPerRiseNumber[$workRiseNumber] = new TimeSpanCollection();
			}
			$workRiseActiveSpanCollectionPerRiseNumber[$workRiseNumber]->add($workRiseActiveSpan, false);
		}
		ksort($workRiseActiveSpanCollectionPerRiseNumber, SORT_NATURAL);
		return $workRiseActiveSpanCollectionPerRiseNumber;
	}

	/**
	 * The settings can state that if paid time is earned during a work rise, it should
	 * be forced to a particular compensation e.g. you may want to turn all overtime to
	 * 100% overtime for working at a certain time of day
	 *
	 * @param int                $workRiseNumber
	 * @param TimeSpanCollection $workRiseActiveCollectionForRiseNumber
	 * @param TimeSpanCollection $timeSpanCollectionThatCanEarnWorkRise This needs to be passed as reference i.e. this collection will be modified and then should be merged back to the collection for the UserSalaryDay
	 *
	 * @return void
	 * @throws FunctionalityNotImplementedYetException
	 * @throws InvalidArgumentException
	 * @throws LogicException
	 * @throws QueryException
	 * @throws SetupException
	 */
	protected function forceTimeThatCanEarnWorkRiseToOvertimePercentage(int $workRiseNumber, TimeSpanCollection $workRiseActiveCollectionForRiseNumber, TimeSpanCollection $timeSpanCollectionThatCanEarnWorkRise): void {
		$forceBasicTimeToNewPayType = $this->userSalaryDay->getSettingValue('workRiseForceBasicTimeOfUnderlyingShift', $workRiseNumber);
		if ('' !== Filter::trim($forceBasicTimeToNewPayType)) {
			$dayHasObligation = true; // If they have earned basic time we know there must be obligation on the day
			$spanTypesToForce = [TimeSpanBasicTime::class];
			$requiredParams = [];
			$blockingParams = ['originatedFromArray' => ORIGINATED_BORROWED_FROM_ACCRUAL, 'earningForced' => true];
			$tagForcedTime = ORIGINATED_BASIC_TIME_FORCED_TO_SALARY_TYPE_BY_WORK_RISE;
			$overtimePaidWithFixedSalaryInsteadOfPercentages = ('Y' === $this->userSalaryDay->getSettingValue('overtimePaidWithFixedSalaryInsteadOfPercentages', 1));
			if (!$overtimePaidWithFixedSalaryInsteadOfPercentages) {
				$overtimeAdditionalPercentage = (int) $this->userSalaryDay->getSettingValue('TesRisePercentage', 1, DEFAULT_OVERTIME_ADDITIONAL_PERCENTAGE);
			} else {
				$overtimeAdditionalPercentage = 0;
			}
			$timeSpanCollectionThatCanEarnWorkRise->forceSalaryTimeSpansToPercentageOrPayTarget($spanTypesToForce, $requiredParams, $blockingParams, $forceBasicTimeToNewPayType, $workRiseActiveCollectionForRiseNumber, $tagForcedTime, $dayHasObligation, $overtimeAdditionalPercentage);
		}
		$forceAdditionalWorkToNewPayType = $this->userSalaryDay->getSettingValue('workRiseForceAdditionalWorkOfUnderlyingShift', $workRiseNumber);
		if ('' !== Filter::trim($forceAdditionalWorkToNewPayType)) {
			$dayHasObligation = ($this->userSalaryDay->getDailyObligation(false) > 0);
			$spanTypesToForce = [TimeSpanOvertimeAdditionalWork::class];
			$requiredParams = [];
			$blockingParams = ['originatedFromArray' => ORIGINATED_BASIC_TIME_FORCED_TO_SALARY_TYPE_BY_WORK_RISE, 'earningForced' => true];
			$tagForcedTime = ORIGINATED_ADDITIONAL_WORK_FORCED_TO_SALARY_TYPE_BY_WORK_RISE;
			$timeSpanCollectionThatCanEarnWorkRise->forceSalaryTimeSpansToPercentageOrPayTarget($spanTypesToForce, $requiredParams, $blockingParams, $forceAdditionalWorkToNewPayType, $workRiseActiveCollectionForRiseNumber, $tagForcedTime, $dayHasObligation);
		}
		$forceOvertimeToNewPayType = $this->userSalaryDay->getSettingValue('workRiseForceOvertimeOfUnderlyingShift', $workRiseNumber);
		if ('' !== Filter::trim($forceOvertimeToNewPayType)) {
			$includeAdditionalWork = false; // additional is handled separately above, so don't include here
			$spanTypesToForce = constantFactory::getConstant_OVERTIME_SPAN_TYPES($includeAdditionalWork);
			$tagForcedTime = ORIGINATED_NON_ADDITIONAL_OVERTIME_FORCED_TO_SALARY_TYPE_BY_WORK_RISE;
			$requiredParams = [];
			$blockingParams = ['originatedFromArray' => [ORIGINATED_BASIC_TIME_FORCED_TO_SALARY_TYPE_BY_WORK_RISE, ORIGINATED_ADDITIONAL_WORK_FORCED_TO_SALARY_TYPE_BY_WORK_RISE], 'earningForced' => true];
			$dayHasObligation = null;
			$timeSpanCollectionThatCanEarnWorkRise->forceSalaryTimeSpansToPercentageOrPayTarget($spanTypesToForce, $requiredParams, $blockingParams, $forceOvertimeToNewPayType, $workRiseActiveCollectionForRiseNumber, $tagForcedTime, $dayHasObligation);
		}
		$forceUnpaidOvertimeToNewPayType = $this->userSalaryDay->getSettingValue('workRiseForceUnpaidOvertimeOfUnderlyingShift', $workRiseNumber);
		if ('' !== Filter::trim($forceUnpaidOvertimeToNewPayType)) {
			$spanTypesToForce = [TimeSpanUnpaidOvertime::class];
			$tagForcedTime = ORIGINATED_UNPAID_OVERTIME_FORCED_TO_SALARY_TYPE_BY_WORK_RISE;
			$requiredParams = [];
			$blockingParams = ['earningForced' => true];
			$dayHasObligation = null;
			$timeSpanCollectionThatCanEarnWorkRise->forceSalaryTimeSpansToPercentageOrPayTarget($spanTypesToForce, $requiredParams, $blockingParams, $forceOvertimeToNewPayType, $workRiseActiveCollectionForRiseNumber, $tagForcedTime, $dayHasObligation);
		}
	}

	/**
	 * The compensation earned for work during a work rise is not necessarily one to one.
	 * It can be scaled by changing a setting e.g. 50% compensation for evening rise.
	 *
	 * @param int                $workRiseNumber
	 * @param TimeSpanCollection $earnedWorkRiseCollectionForWorkRiseNumber
	 *
	 * @return TimeSpanCollection
	 * @throws InvalidArgumentException
	 * @throws LogicException
	 * @throws SetupException
	 */
	protected function earnWorkRisePercentagePart(int $workRiseNumber, TimeSpanCollection $earnedWorkRiseCollectionForWorkRiseNumber): TimeSpanCollection {
		$returnCollection = new TimeSpanCollection();
		$workRiseDayTotal = 0;
		$durationAddedForPercentagePart = 0;
		$negativeDurationAddedForPercentagePart = 0;
		$percentageExpected = 0;
		$increaseMethodExpected = 0;

		// For each work rise we loop through, check how much percentage bonus can be earned, and then create spans based off that
		$negativeTimeToAdd = 0;
		$timeDependantWorkRisePercentage = 0;
		$blockingRises = [];
		$accrualTargetForPercentagePart = null;

		foreach ($earnedWorkRiseCollectionForWorkRiseNumber->getTimeSpanArray() as $workRiseSpan) {
			/** @var TimeSpanWorkRise $workRiseSpan */
			$timeDependantWorkRisePercentage = $workRiseSpan->workRisePercentage;
			$timeDependantWorkRiseIncreaseMethod = $workRiseSpan->workRiseIncreaseMethod;
			if ('0.00' !== number_format($timeDependantWorkRisePercentage, 2, '.', '')) {
				$percentageExpected = $timeDependantWorkRisePercentage;
				$increaseMethodExpected = $timeDependantWorkRiseIncreaseMethod;
				$workRiseDayTotal += $workRiseSpan->getDurationInSeconds();

				$baseDuration = $workRiseSpan->getDurationInSeconds();
				$durationPercentagePart = RoundSeconds::toNearestMinute(floor($baseDuration * $timeDependantWorkRisePercentage / 100));
				$accrualTargetForPercentagePart = $workRiseSpan->workRiseAccrualTarget;
				if ($durationPercentagePart > 0) {
					/* @var $timeSpan TimeSpanWorkRisePercentagePartByRiseNumber */
					$timeSpan = $workRiseSpan->duplicate(TimeSpanWorkRisePercentagePartByRiseNumber::class);
					if ($durationPercentagePart > $workRiseSpan->getDurationInSeconds()) {
						// Additional time we create should be tagged so we can handle it correctly when dealing with "by day" salary rendering
						/* @var $extraTimeAwarded TimeSpanWorkRisePercentagePartByRiseNumber */
						$extraTimeAwarded = $workRiseSpan->duplicate(TimeSpanWorkRisePercentagePartByRiseNumber::class);
						$extraTimeAwarded->setBeginDateTimeAsTimeStamp($workRiseSpan->getEndDateTimeAsTimeStamp());
						$extraTimeAwarded->addSecondsToEnd($durationPercentagePart - $workRiseSpan->getDurationInSeconds());
						$extraTimeAwarded->originatedFromArray[] = ORIGINATED_TIME_CREATED_DUE_TO_ROUNDING_UP_OR_MULTIPLYING;
						$returnCollection->add($extraTimeAwarded, true, false);
					} else {
						$timeSpan->setBeginAndEndDateTimeAsTimeStamp($workRiseSpan->getBeginDateTimeAsTimeStamp());
						$timeSpan->addSecondsToEnd($durationPercentagePart);
					}
					$returnCollection->add($timeSpan, true, false);
					$durationAddedForPercentagePart += $durationPercentagePart;

				// Negative value, needs a balance change as otherwise it won't work
				// Negative percentage is not supported if work rise percentage targets salary.
				} elseif (ActivityPayTarget::SALARY !== $workRiseSpan->workRiseAccrualTarget && ActivityPayTarget::UNCOMPENSATED !== $workRiseSpan->workRiseAccrualTarget) {
					$negativeTimeToAdd += (int) floor($baseDuration * $timeDependantWorkRisePercentage / 100);
					$blockingRises = $workRiseSpan->blockingWorkRises;
				}
			}
		}
		if (0 !== $negativeTimeToAdd && $accrualTargetForPercentagePart) {
			$negativeTimeToAdd = RoundSeconds::toNearestMinute($negativeTimeToAdd);
			$dateTime = $this->userSalaryDay->getSalaryRenderingDate() . ' 00:00:00';
			$newDerivedInMadeBalanceChangeTimeSpan = new TimeSpanWorkRiseNegativePercentagePartByRiseNumber();
			$newDerivedInMadeBalanceChangeTimeSpan->setBeginDateTime($dateTime);
			$newDerivedInMadeBalanceChangeTimeSpan->setApproverId(APPROVED_BY_SYSTEM);
			$newDerivedInMadeBalanceChangeTimeSpan->number = $workRiseNumber;
			$newDerivedInMadeBalanceChangeTimeSpan->setEndDateTimeFromBeginDateTimeAsAddedSeconds(1);
			$newDerivedInMadeBalanceChangeTimeSpan->value = $negativeTimeToAdd;
			$newDerivedInMadeBalanceChangeTimeSpan->valueInMinutes = (int) ($negativeTimeToAdd / 60);
			$newDerivedInMadeBalanceChangeTimeSpan->workRiseAccrualTarget = $accrualTargetForPercentagePart;
			$newDerivedInMadeBalanceChangeTimeSpan->workRisePercentage = $timeDependantWorkRisePercentage;
			$newDerivedInMadeBalanceChangeTimeSpan->workRiseIncreaseMethod = TimeSpanWorkRise::INCREASE_METHOD_PERCENTAGE;
			$newDerivedInMadeBalanceChangeTimeSpan->blockingWorkRises = $blockingRises;
			$returnCollection->add($newDerivedInMadeBalanceChangeTimeSpan, true, false);
			$negativeDurationAddedForPercentagePart += $negativeTimeToAdd;
		}

		// At the end we want to check our rounded totals total up to the correct overall percentage for the day, If there's a rounding discrepancy we can add/subtract from the last span
		if ($workRiseDayTotal > 0) {
			$targetTotalPercentageDuration = $this->roundMinutesToCalculate($workRiseDayTotal, $percentageExpected, $increaseMethodExpected);
			if (0 !== $durationAddedForPercentagePart && $durationAddedForPercentagePart !== $targetTotalPercentageDuration) {
				$secondsToAddOrRemove = $targetTotalPercentageDuration - $durationAddedForPercentagePart;
				$returnCollection->addOrRemoveSecondsToEndOfTimeSpanType($secondsToAddOrRemove, TimeSpanWorkRisePercentagePartByRiseNumber::class, ['number' => $workRiseNumber], null, ORIGINATED_TIME_CREATED_DUE_TO_ROUNDING_UP_OR_MULTIPLYING);
			}
			if (0 !== $negativeDurationAddedForPercentagePart && $negativeDurationAddedForPercentagePart !== $targetTotalPercentageDuration) {
				$secondsToAddOrRemove = $targetTotalPercentageDuration - $negativeDurationAddedForPercentagePart;
				$returnCollection->addOrRemoveSecondsToEndOfTimeSpanType($secondsToAddOrRemove, TimeSpanWorkRiseNegativePercentagePartByRiseNumber::class, ['number' => $workRiseNumber], null, ORIGINATED_TIME_CREATED_DUE_TO_ROUNDING_UP_OR_MULTIPLYING, 'value');
			}
		}
		return $returnCollection;
	}

	/**
	 * @param int       $totalDurationOfWorkRise Total duration in seconds
	 * @param int|float $percentageExpected
	 * @param int       $increaseMethod
	 *
	 * @return int seconds
	 */
	protected function roundMinutesToCalculate(int $totalDurationOfWorkRise, $percentageExpected, int $increaseMethod): int {
		if ($increaseMethod === TimeSpanWorkRise::INCREASE_METHOD_PERCENTAGE) {
			return (int) (round(($totalDurationOfWorkRise * ($percentageExpected / 100)) / 60) * 60);
		}

		$floorTo = round($percentageExpected / 100 * 3600); // Change 3600 to 1800 to make earning for each ½ hour etc.
		return (int) (floor(round($totalDurationOfWorkRise * ($percentageExpected / 100)) / $floorTo) * $floorTo);
	}

	/**
	 * @param TimeSpanCollection $salaryDayTimeSpanCollection
	 *
	 * @return ActivityType[]
	 * @throws InvalidArgumentException
	 * @throws QueryException
	 * @throws SetupException
	 */
	protected function getActivityTypesInCollection(TimeSpanCollection $salaryDayTimeSpanCollection): array {
		$activityTypesInCollection = [];
		foreach ($salaryDayTimeSpanCollection->getTimeSpanArray() as $timeSpan) {
			$activity = $timeSpan->getDerivedFromActivity();
			if (null === $activity) {
				continue;
			}
			try {
				$activityType = $activity->getActivityType();
			} catch (EventTypeNotFoundException $e) {
				continue;
			}

			$activityTypesInCollection[(int) $activity->getActivityTypeId()] = $activityType;
		}
		return $activityTypesInCollection;
	}

	/**
	 * Depending on settings, we sometimes only want to earn work rises if the work shift starts before/after a certain time
	 * i.e. day shift workers shouldn't earn evening rises.
	 *
	 * @param int $riseNumber
	 * @return bool
	 */
	protected function shouldWorkRiseBeEarnedBasedOnTheTypeOfTheShift(int $riseNumber): bool {
		$salaryDayTimeSpanCollection = $this->userSalaryDay->getTimeSpanCollection();

		// Some customers only want year leave work rises to be earned if there was a planned shift for instance
		if (
			('Y' === $this->userSalaryDay->getSettingValue('workRiseOnlyEarnedOnPlannedShifts', $riseNumber)) &&
			false === $salaryDayTimeSpanCollection->hasTimeSpansOfType(TimeSpanSalaryRenderingSection::class, ['accuracyOfSalaryRenderingSectionTimeSpan' => TimeSpanSalaryRenderingSection::TIME_SPAN_COMES_FROM_PLANNED_OR_EXPECTED_WORK])
		) {
			return false;
		}

		// Some customers only want the work rise to be earned if the shift AFTER before a particular time in the day
		$workRiseNotCreatedIfWorkStartsBeforeMinutes = $this->userSalaryDay->getSettingValue('workRiseNotActiveIfWorkStartsBefore', $riseNumber);
		if (null !== $workRiseNotCreatedIfWorkStartsBeforeMinutes) {
			$workRiseNotCreatedIfWorkStartsBefore = TimeSpan::addSecondsToDate($this->userSalaryDay->getSalaryRenderingDate(), 60 * $workRiseNotCreatedIfWorkStartsBeforeMinutes);
			$firstWorkSpan = $salaryDayTimeSpanCollection->getEarliestTimeSpan($this->paidAndUnpaidSpanTypes);
			if (null !== $firstWorkSpan && $firstWorkSpan->getBeginDateTimeAsTimeStamp() < $workRiseNotCreatedIfWorkStartsBefore) {
				return false;
			}
		}

		// Some customers only want the work rise to be earned if the shift starts BEFORE a particular time in the day
		$workRiseNotCreatedIfWorkStartsAfterMinutes = $this->userSalaryDay->getSettingValue('workRiseNotActiveIfWorkStartsAfter', $riseNumber);
		if (null !== $workRiseNotCreatedIfWorkStartsAfterMinutes) {
			$workRiseNotCreatedIfWorkStartsAfter = TimeSpan::addSecondsToDate($this->userSalaryDay->getSalaryRenderingDate(), 60 * $workRiseNotCreatedIfWorkStartsAfterMinutes);
			$firstWorkSpan = $salaryDayTimeSpanCollection->getEarliestTimeSpan($this->paidAndUnpaidSpanTypes);
			if (null !== $firstWorkSpan && $firstWorkSpan->getBeginDateTimeAsTimeStamp() >= $workRiseNotCreatedIfWorkStartsAfter) {
				return false;
			}
		}

		// Some customers only want the work rise to be earned if daily overtime starts AFTER a particular time in the day
		$workRiseNotCreatedIfDailyOvertimeStartsBeforeMinutes = $this->userSalaryDay->getSettingValue('workRiseNotActiveIfDailyOvertimeStartsBefore', $riseNumber);
		if (null !== $workRiseNotCreatedIfDailyOvertimeStartsBeforeMinutes) {
			$workRiseNotCreatedIfDailyOvertimeStartsBefore = TimeSpan::addSecondsToDate($this->userSalaryDay->getSalaryRenderingDate(), 60 * $workRiseNotCreatedIfDailyOvertimeStartsBeforeMinutes);
			$firstOvertimeSpan = $salaryDayTimeSpanCollection->getEarliestTimeSpan([TimeSpanOvertimeDaily50::class, TimeSpanOvertimeDaily100::class]);
			if (null !== $firstOvertimeSpan && $firstOvertimeSpan->getBeginDateTimeAsTimeStamp() < $workRiseNotCreatedIfDailyOvertimeStartsBefore) {
				return false;
			}
		}

		// Some customers only want the work rise to be earned if daily overtime starts BEFORE a particular time in the day
		$workRiseNotCreatedIfDailyOvertimeStartsAfterMinutes = $this->userSalaryDay->getSettingValue('workRiseNotActiveIfDailyOvertimeStartsAfter', $riseNumber);
		if (null !== $workRiseNotCreatedIfDailyOvertimeStartsAfterMinutes) {
			$workRiseNotCreatedIfDailyOvertimeStartsAfter = TimeSpan::addSecondsToDate($this->userSalaryDay->getSalaryRenderingDate(), 60 * $workRiseNotCreatedIfDailyOvertimeStartsAfterMinutes);
			$firstOvertimeSpan = $salaryDayTimeSpanCollection->getEarliestTimeSpan([TimeSpanOvertimeDaily50::class, TimeSpanOvertimeDaily100::class]);
			if (null !== $firstOvertimeSpan && $firstOvertimeSpan->getBeginDateTimeAsTimeStamp() >= $workRiseNotCreatedIfDailyOvertimeStartsAfter) {
				return false;
			}
		}
		return true;
	}

	/**
	 * If this salary concept has any specific dependencies or gets reordered a lot due to settings it's good to add
	 * it's dependencies so we can double check whether everything has been run
	 * NOTE: dependencies only apply to salary concepts run at the same level (section, week, day etc)
	 *
	 * @return array
	 */
	protected function getDependencies(): array {
		return []; // TODO: Implement getDependencies() method.
	}

	/**
	 * Remove any earned work rises that have "do not earn if following work rise(s) have already been earned" set.
	 * This works in a following way: Two work rises
	 * - Work rise 1 (12-24), do not earn if rise 2 has been earned
	 * - Work rise 2 (00-12), do not earn if rise 1 has been earned
	 *
	 * With work from 11-18, only rise 2 will be earned
	 * With work from 22-06, only rise 1 will be earned
	 * @link https://gemini.nepton.com/workspace/0/item/18081
	 *
	 * @return void
	 */
	private function removeWorkRisesThatShouldNotBeEarnedDueToEarningAnotherRise(): void {
		$this->userSalaryDay->getTimeSpanCollection()->sortByBeginTime();
		$earnedWorkRises = [];

		/** @var TimeSpanWorkRise[] $workRiseSpans */
		$workRiseSpans = $this->userSalaryDay->getTimeSpanCollection()->getTimeSpansByClassType(TimeSpanWorkRise::class);
		foreach ($workRiseSpans as $workRiseSpan) {
			$riseNumber = $workRiseSpan->number;
			$spansDeleted = false;
			$blockingRises = $workRiseSpan->blockingWorkRises;
			if (empty($blockingRises)) {
				$earnedWorkRises[$riseNumber] = true;
				continue;
			}

			foreach ($blockingRises as $blockingRise) {
				if (isset($earnedWorkRises[$blockingRise])) {
					$spansDeleted = true;
					$this->userSalaryDay->getTimeSpanCollection()->removeByGUID($workRiseSpan->guid);
					break;
				}
			}
			if (!$spansDeleted) {
				$earnedWorkRises[$riseNumber] = true;
			}
		}
	}
}
