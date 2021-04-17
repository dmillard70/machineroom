<?php

namespace Dampf\Machineroom;

/**
 * Scheduler class to schedule jobs.
 *
 * @author Daniel Millard <dampf@millard.de>
 */
class Scheduler
{
	public const MINUTE = 0;
	public const HOUR = 1;
	public const DAY = 2;
	public const MONTH = 3;
	public const WEEKDAY = 4;

	public const CRONCODES = [
		'@yearly' => '0 0 1 1 *',
		'@annually' => '0 0 1 1 *',
		'@monthly' => '0 0 1 * *',
		'@weekly' => '0 0 * * 0',
		'@daily' => '0 0 * * *',
		'@midnight' => '0 0 * * *',
		'@hourly' => '0 * * * *',
	];

	private const CRONPARTRANGES = [
		[0,59],
		[0,23],
		[1,31],
		[1,12],
		[0,6]
	];

	private const MONTHCODES = [ 1 => 'jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec' ];
	private const WEEKDAYCODES = [ -7 => '-sun', 0 => 'sun', 1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat' ];

	private $aCronParts;

	private $bUnlimitedDays = false;
	private $bUnlimitedWeekdays = false;

	private $sTimeZone = null;

    /**
     * Validate a cron expression.
     *
     * @param string 	$sCronExpression 	cron expression for validation
     *
     * @return bool 	true valid expression, false invalid expression
     */
	public static function isValidCronExpression(string $sCronExpression)
    {
		if (empty($sCronExpression)) return false;
		try {
			new Scheduler($sCronExpression);
        } catch (\InvalidArgumentException $e) {
			return false;
		}
		return true;
	}

	/**
	 * New scheduler with cron expression.
	 *
	 * @param string|null 	$sCronExpression 	Cron expression
	 * @param string|null 	$sTimeZone 			Name of timezone
	 */
	public function __construct(string $sCronExpression = null, string $sTimeZone = null)
	{
		$this->reset($sTimeZone);
		if ( !empty($sCronExpression) ) {
	        $this->setCronExpression($sCronExpression);
		}
    }

    /**
     * Reset cron expression.
     *
	 * @param string|null 	$sTimeZone 			Name of timezone
	 * 
     * @return Scheduler
     */
	public function reset(string $sTimeZone = null)
	{
		$this->aCronParts = [ [], [], [], [], [] ];
		$this->bLimitedDays = false;
		$this->bLimitedDaysOfWeek = false;
		$this->setTimeZone($sTimeZone);
		return $this;
	}

	/**
     * Set TimeZone to use.
     *
     * @param string|\DateTimeInterface|null 	$vCurrentTime 	Timezone as string, or from DateTimeInterface or null for system default
     *
     * @return Scheduler
     */
    public function setTimeZone($vCurrentTime = null)
    {
		if ( is_string($vCurrentTime) && 'now' !== $vCurrentTime && in_array($vCurrentTime,\DateTimeZone::listIdentifiers())) {
			$this->sTimeZone = $vCurrentTime;
		} else {
			$this->sTimeZone = $vCurrentTime instanceof \DateTimeInterface ? $vCurrentTime->getTimeZone()->getName() : date_default_timezone_get();
		}
		return $this;
    }

    /**
     * Set scheduler with valid cron expression (f.e. * * * * *)
	 * Part 1, index 0: minute (0-59)
	 * Part 2, index 1: hour (0-23)
	 * Part 3, index 2: day (0-31)
	 * Part 4, index 3: month (1-12) or jan,feb,mar,... 
	 * Part 5, index 4: weekday (0-7, sunday is 0 or 7) or mon, tue, wed, ... 
	 * All parts seperated by spaces
	 * more than one argument seperated by comma, f.e. 2,4,5
	 * with - you can giv ranges, f.e. 3-7 means 3,4,5,6,7
	 * with / you can define intervals, f.e. 3-9/2 means 3,5,7,9
	 * a * means complete range (no limits)
	 * if both, day and weekday have a limit (no *) the due calculation is made with both
	 * No support for hashtag #, question mark ?, L and W
	 * Have a look at https://en.wikipedia.org/wiki/Cron 
     *
     * @param string $sCronExpression 		valid cron expression Cron Expression look at https://de.wikipedia.org/wiki/Cron
     *
     * @throws \InvalidArgumentException if not a valid CRON expression
     *
     * @return Scheduler
     */
    public function setCronExpression(string $sCronExpression)
    {
		$sCronExpression = strtolower($sCronExpression);
		$sCronExpression = self::CRONCODES[$sCronExpression] ?? $sCronExpression;

        $aSplit = preg_split('/\s/', $sCronExpression, -1, PREG_SPLIT_NO_EMPTY);
        if ( $aSplit === false || count($aSplit) != 5) {
            throw new \InvalidArgumentException(
                $sCronExpression . ' is not a valid CRON expression'
            );
        }
        foreach ($aSplit as $iPosition => $sValue) {
            $this->setCronPart($iPosition, $sValue);
        }

		if ( $this->bUnlimitedWeekdays === true ) $this->aCronParts[self::WEEKDAY] = [];
		elseif ( $this->bUnlimitedDays === true ) $this->aCronParts[self::DAY] = [];

		return $this;
    }

    /**
     * Set a part of the cron expression.
     *
     * @param int 		$iPosition 	The part (index #) of the cron expression 
     * @param string	$sValue 	value to set
     *
     * @throws \InvalidArgumentException if the value is not valid for the part
     *
     * @return Scheduler
     */
	public function setCronPart(int $iPosition, string $sValue)
	{
		list($iMin,$iMax) = self::CRONPARTRANGES[$iPosition];

		if ($iPosition == self::WEEKDAY) {
			$sValue = str_ireplace( self::WEEKDAYCODES, array_keys(self::WEEKDAYCODES), $sValue);
			$this->bUnlimitedWeekdays = false;
		} elseif ($iPosition == self::MONTH) {
			$sValue = str_ireplace( self::MONTHCODES, array_keys(self::MONTHCODES), $sValue);
		} elseif ($iPosition == self::DAY ) {
			$this->bUnlimitedDays = false;
		}
        $aValues = explode(',',$sValue);

        $aCronValues = [];
        $bError = false;

        foreach ($aValues as $sVal) {
            $sRegex = '/(^\*\/\d+$){1}|(^\*$){1}|(^\d+\-\d+(?:\/\d+)?$){1}|(^\d+$){1}/';
			unset ($aMatches);
	    	if ( !preg_match_all($sRegex, $sVal, $aMatches, PREG_SET_ORDER, 0) || empty($aMatches[0]) ) {
		    	throw new \InvalidArgumentException(
			    	'Invalid CRON field value ' . $sValue . ' at position ' . $iPosition
			    );
	    	}
    		if (!empty($aMatches[0][2])) {
				if ($iPosition == self::DAY ) $this->bUnlimitedDays = true;
			    elseif ($iPosition == self::WEEKDAY ) $this->bUnlimitedWeekdays = true;
		        for ($i = $iMin; $i <= $iMax; $i++) {
					$aCronValues[] = $i;
				}
			} elseif ( !empty($aMatches[0][1]) || !empty($aMatches[0][3])) {
				$sMatch = !empty($aMatches[0][1]) ? $iMin."-".$iMax.substr($aMatches[0][1],1) : $aMatches[0][3];
				if (strpos($sMatch,'/') === false) $sMatch .= "/1";
				list ( $sRange, $iRepetition) = explode ( '/', $sMatch); 
				list ( $iStartRange, $iEndRange) = explode ( '-', $sRange);
				if ( $iStartRange >= $iEndRange || $iRepetition == 0 || $iStartRange < $iMin || $iEndRange > ($iMax+1) || ($iPosition != self::WEEKDAY && $iEndRange > $iMax) ) {
					$bError = true;
				} else {
					for ($i = (int) $iStartRange; $i <= $iEndRange; $i += $iRepetition ) {
						$aCronValues[] = ($iPosition == self::WEEKDAY && $i == 7 ) ? 0 : $i;
					}
				}
			} else {
				$iWorkValue = (int) ($iPosition == self::WEEKDAY && $aMatches[0][4] == 7) ? 0 : $aMatches[0][4];
				if ($iWorkValue >= $iMin && $iWorkValue <= $iMax) {
					$aCronValues[] = $iWorkValue;
				} else {
					$bError = true;
				}
			}
			if ($bError) {
				throw new \InvalidArgumentException(
					'Invalid CRON field value ' . $sValue . ' at position ' . $iPosition
				);
			}
		}
        $this->aCronParts[$iPosition] = array_unique($aCronValues,SORT_NUMERIC);
		if (!empty($this->aCronParts[$iPosition])) sort( $this->aCronParts[$iPosition], SORT_NUMERIC );

        return $this;
    }

	/**
     * Calculate DateTime depending on given argument, set timezone
	 * will set seconds to zero.
     *
     * @param string|\DateTimeInterface 	$vTime Relative calculation date
	 * 
	 * @throws \InvalidArgumentException 	if the given time could not be interpreted
     *
     * @return DateTime 	DateTime Object
     */
	public function calculateDateTime ($vTime = 'now')
	{
        if ('now' === $vTime) {
            $oTime = new \DateTime();
        } elseif (is_string($vTime)) {
            $oTime = new \DateTime($vTime);
		} elseif (is_int($vTime)) {
            $oTime = new \DateTime("@".$vTime);
        } elseif ($vTime instanceof \DateTime || $vTime instanceof \DateTimeImmutable) {
			$oTime = new \DateTime("@".$vTime->getTimestamp());
        } else {
			$oTime = null;
		}
		if (!$oTime) {
			throw new \InvalidArgumentException(
				'Invalid time argument for calculateTime().'
			);
		}
        return $oTime->setTimezone(new \DateTimeZone($this->sTimeZone))->setTime( (int) $oTime->format('H'), (int) $oTime->format('i'), 0);
	}

	/**
     * Determine if the scheduler is due based on a given / current date/time.
	 * If the last due time is greater than the given second parameter the function returns true|array, too.
	 * This behaviour is interesting if you don't call the isDue check every minute, to go sure that no due is passed.
     *
     * @param string|\DateTimeInterface 		$vTime			Time to use for due check
     * @param null|string|\DateTimeInterface	$vLastTime		Last runtime to check too
	 * @param bool								$bResultAsArray returns bool or array
     *
     * @return bool|array  Returns TRUE if the cron is due or FALSE if not OR returns an array with [ bool: is due, \DateTime|null CurrentTime, \DateTime|null matched time ]  
     */
    public function isDue($vTime = 'now', $vLastTime = null, bool $bResultAsArray = false)
    {
		if (empty($this->aCronParts[self::MINUTE]) || empty($this->aCronParts[self::HOUR]) || empty($this->aCronParts[self::MONTH]) || (empty($this->aCronParts[self::DAY]) && empty($this->aCronParts[self::WEEKDAY]))) {
			return $bResultAsArray ? [false, null, null] : false;
		}

		$oTime = $this->calculateDateTime($vTime);

		if ( $this->isMatching($oTime) ) return $bResultAsArray ? [true, $oTime, $oTime] : true;
		if ( $vLastTime === null ) return $bResultAsArray ? [false, $oTime, null] : false;

		$oLastTime = $this->calculateDateTime($vLastTime);
		$oLastDueTime = $this->getLastDue($oTime);

		if ($oLastDueTime > $oLastTime) return $bResultAsArray ? [true, $oTime, $oLastDueTime] : true;
		return $bResultAsArray ? [false, $oTime, null] : false;
	}

	/**
     * Determines if DateTime Object is matching with scheduling.
     *
     * @param \DateTime	$oTime 	datetime to check
     *
     * @return bool		true matches / false it is not matching
     */
	protected function isMatching (\DateTime $oTime) {
		if (
			in_array( (int) $oTime->format('i'), $this->aCronParts[self::MINUTE])
			&& in_array( (int) $oTime->format('H'), $this->aCronParts[self::HOUR])
			&& in_array( (int) $oTime->format('m'), $this->aCronParts[self::MONTH])
			&& ( in_array( (int) $oTime->format('d'), $this->aCronParts[self::DAY]) || in_array( (int) $oTime->format('w'), $this->aCronParts[self::WEEKDAY]) )
		) {
			return true;
		}
		return false;
	}

	/**
     * get the nearast lower value (or last) of a cron part range.
     *
	 * @param int 		$iPosition 	The part (index #) of the cron expression 
     * @param int		$iValue 	value to search for
	 * 
	 * @throws \InvalidArgumentException 	if their are no values for given part (except day and weekday)
	 * 
     * @return int|false nearest lower value or false if error on day or weekday
     */
	public function getNearestLowerValue (int $iPosition, int $iValue) {
		$iCount = count($this->aCronParts[$iPosition]);
		if ($iCount == 0) {
			if ( $iPosition == self::DAY || $iPosition == self::WEEKDAY ) return false;
			throw new \RuntimeException( 'No valid Cron value for part: '.$iPosition );
		}
		if ($iCount == 1) return $this->aCronParts[$iPosition][0];
		if ($iValue == -1) return $this->aCronParts[$iPosition][$iCount-1];
		for ( $i = $iCount-1; $i>=0; $i-- ) {
			if ( $this->aCronParts[$iPosition][$i] < $iValue ) break;
		}
		return $this->aCronParts[$iPosition][ ($i == -1 ? ($iCount-1) : $i) ];
	}

    /**
     * get the last due time, starting with given datetime and subtraction.
     *
     * @param \DateTime|string	starting time to find last due time
	 * @param string			Time (string format for \DateInterval) to subtract from starting time, defalt 1 minute "PT1M"
     *
     * @return \DateTime|false	return last due time or fals if ther is an error
     */
	public function getLastDue($vTime = 'now', string $sSub = 'PT1M')
    {
		if (empty($this->aCronParts[0]) || empty($this->aCronParts[1]) || empty($this->aCronParts[3]) || (empty($this->aCronParts[2]) && empty($this->aCronParts[4]))) {
			return false;
		}

		$oTime = $this->calculateDateTime($vTime);
		if (!empty($sSub)) $oTime->sub(new \DateInterval($sSub));
		if ($this->isMatching($oTime)) return $oTime;

		$iMinute = (int) $oTime->format('i');
		$iHour = (int) $oTime->format('H');

		$iLastMinute = $this->getNearestLowerValue ( self::MINUTE, $iMinute);
		if ( $iLastMinute < $iMinute ) {
			$oTime->setTime( $iHour, $iLastMinute, 0);
			if ($this->isMatching($oTime)) return $oTime;
		}
		$iMinute = $this->getNearestLowerValue ( self::MINUTE, -1);

		
		$iLastHour = $this->getNearestLowerValue ( self::HOUR, $iHour);
		if ( $iLastHour < $iHour ) {
			$oTime->setTime( $iLastHour, $iMinute, 0);
			if ($this->isMatching($oTime)) return $oTime;
		}
		$iHour = $this->getNearestLowerValue ( self::HOUR, -1);		

		$oTime->setTime( $iHour, $iMinute, 0);
		$oTimeDay = clone $oTime;
		$oTimeWeekDay = clone $oTime;

        $iDay = (int) $oTimeDay->format('d');
		if ( false === ($iLastDay = $this->getNearestLowerValue ( self::DAY, $iDay)) ) {
				$oTimeDay = null;
		} else {
			$iMonth = (int) $oTimeDay->format('m');
			$iYear = (int) $oTimeDay->format('Y');
			if ($iLastDay >= $iDay) {
				$iMonth == 1 ? 12 : ($iMonth-1);
				if ($iMonth == 12) $iYear--;
				$iLastDay = $this->getNearestLowerValue ( self::DAY, -1);
			}

			$iLastMonth = $this->getNearestLowerValue ( self::MONTH, $iMonth + 1 );
			if ($iLastMonth > $iMonth) {
				$iYear--;
				$iLastDay = $this->getNearestLowerValue ( self::DAY, -1);
			}
			$iDayIndex = array_search ($iLastDay, $this->aCronParts[self::DAY]);
			$iMonthIndex = array_search ($iLastMonth, $this->aCronParts[self::MONTH]);

			for ( $i = count($this->aCronParts[self::MONTH]); $i>=0 ; $i-- ){
				$iCurrentDay = $this->aCronParts[self::DAY][$iDayIndex];
				$iCurrentMonth = $this->aCronParts[self::MONTH][$iMonthIndex];

				$iDaysInMonth = cal_days_in_month(CAL_GREGORIAN, $iCurrentMonth, $iYear);
				if ($iCurrentDay <= $iDaysInMonth) break;
				$iCheckDay = $iCurrentDay;
				$iCurrentDay = $this->getNearestLowerValue ( self::DAY, $iCurrentDay);	// Worst case Day 30
				if ($iCurrentDay < $iCheckDay && $iCurrentDay <= $iDaysInMonth) break; 
				elseif ($iCurrentDay < $iCheckDay) {
					$iCheckDay = $iCurrentDay;
					$iCurrentDay = $this->getNearestLowerValue ( self::DAY, $iCurrentDay); // Worst case Day 29
					if ($iCurrentDay < $iCheckDay && $iCurrentDay <= $iDaysInMonth) break;
					elseif ($iCurrentDay < $iCheckDay) {
						$iCheckDay = $iCurrentDay;
						$iCurrentDay = $this->getNearestLowerValue ( self::DAY, $iCurrentDay); // Worst case Day 28
						if ($iCurrentDay < $iCheckDay) break;
					}
				}
				$iDayIndex = count($this->aCronParts[self::DAY])-1;		// Last day in list
				if ($iMonthIndex == 0) {
					$iMonthIndex = count($this->aCronParts[self::MONTH])-1;
					$iYear--;
				} else $iMonthIndex--;				
			}
			if ($i==-1) {	// Check for leap year
				if (in_array(2,$this->aCronParts[self::MONTH]) && in_array(29,$this->aCronParts[self::DAY])) {
					// Calculate Leap Year
					$iYear = $iYear - ($iYear % 4);
					if ( $iYear % 4 != 0 || ($iYear % 100 == 0 && $iYear % 400 != 0) ) $iYear -= 4; 
					$oTimeDay->setDate ( $iYear , 2 , 29 );	
				} else $oTimeDay = null;
			} else {
				$oTimeDay->setDate ( $iYear , $iCurrentMonth , $iCurrentDay );	
			}
		}

        $iWeekDay = (int) $oTimeWeekDay->format('w');
		if ( false === ($iLastWeekDay = $this->getNearestLowerValue( self::WEEKDAY, $iWeekDay)) ) {
			$oTimeWeekDay = null;
		} else {
			$oTimeWeekDay->sub(new \DateInterval('P'.( ($iWeekDay - $iLastWeekDay) + ($iLastWeekDay < $iWeekDay ? 0 : 7) ).'D'));
			if (!$this->isMatching($oTimeWeekDay)) {
				$iMonth = (int) $oTimeWeekDay->format('m');
				$iYear = (int) $oTimeWeekDay->format('Y');
				$iLastMonth = $this->getNearestLowerValue ( self::MONTH, $iMonth );
				if ($iLastMonth >= $iMonth) $iYear--;
				$iDaysInMonth = cal_days_in_month(CAL_GREGORIAN, $iLastMonth, $iYear);

				$oTimeWeekDay->setDate ( $iYear , $iLastMonth , $iDaysInMonth );	
				$iWeekDay = (int) $oTimeWeekDay->format('w');
				$iLastWeekDay = $this->getNearestLowerValue( self::WEEKDAY, $iWeekDay+1);

				if ($iLastWeekDay != $iWeekDay) {
					$oTimeWeekDay->sub(new \DateInterval('P'.( ($iWeekDay - $iLastWeekDay) + ($iLastWeekDay < $iWeekDay ? 0 : 7) ).'D'));
				}
				if (!$this->isMatching($oTimeWeekDay)) {
					$oTimeWeekDay = null;
				}
			} 
		}

		if (empty($oTimeWeekDay) && empty ($oTimeDay)) return false;
		if (!empty($oTimeWeekDay) && !empty ($oTimeDay)) {
            return $oTimeDay >= $oTimeWeekDay ? $oTimeDay : $oTimeWeekDay;
        }
		return empty($oTimeWeekDay) ? $oTimeDay : $oTimeWeekDay;
    }

    /**
     * Get scheduling times as cron expression.
     *
     * @return null|string Returns the cron expression or NULL if there is an error
     */
    public function getCronExpression()
    {
		if (empty($this->aCronParts[self::MINUTE]) || empty($this->aCronParts[self::HOUR]) || empty($this->aCronParts[self::MONTH]) || (empty($this->aCronParts[self::DAY]) && empty($this->aCronParts[self::WEEKDAY]))) {
			return null;
		}

		$sResult = '';
		foreach ($this->aCronParts as $iPosition => $aValue) {
            $sTemp = $this->getCronPart($iPosition);
			if (null === $sTemp) return null;
			$sResult .= $sTemp." ";
        }
		return trim($sResult);
    }

    /**
     * Get the parts of the scheduling times as cron expression part.
     *
	 * @param int 		$iPosition 	The part (index #) of the cron expression 
	 * 
     * @return null|string 	Returns the cron expression or NULL if there is an error
     */
    public function getCronPart(int $iPosition)
    {
		$iCount = count($this->aCronParts[$iPosition]);
		list($iMin,$iMax) = self::CRONPARTRANGES[$iPosition];
		$iCountRange = $iMax - $iMin + 1;

		if ( $iPosition == self::DAY || $iPosition == self::WEEKDAY ) {
			$iCountOther = count($this->aCronParts[ ($iPosition == self::DAY ? self::WEEKDAY : self::DAY) ]);
			if ( $iCount == 0 && $iCountOther == 0) return null;
			if ( ($iCount == 0 && $iCountOther != 0) || ($iCountOther == 0 && $iCount == $iCountRange) ) return '*';
		} elseif ( $iCount == $iCountRange ) {
			return '*';
		} elseif ($iCount == 0) {
			return null;
		}
		if ($iCount == 1) return (string) $this->aCronParts[$iPosition][0];
		if ($iCount > 2 ) {
			// Try to detect a pattern
			$iCount = count($this->aCronParts[$iPosition]);
			$iDiff = null;
			for ( $i=0; $i < ($iCount-1); $i++ ) {
				$iTemp = $this->aCronParts[$iPosition][$i+1] - $this->aCronParts[$iPosition][$i];
				if ($iDiff !== null && $iTemp != $iDiff) {
					$iDiff = null;
					break;
				}
				$iDiff = $iTemp;
			}
			if ( !is_null($iDiff) ) {
				$iStart = $this->aCronParts[$iPosition][0];
				$iEnd = $this->aCronParts[$iPosition][$iCount-1];
				if ($iStart == $iMin && ($iEnd + $iDiff) > $iMax) {
					return '*'.( $iDiff == 1 ? '' : '/'.$iDiff );
				} else {
					return $iStart."-".$iEnd.( $iDiff == 1 ? '' : '/'.$iDiff );
				}
			}
		}
		return implode(',',$this->aCronParts[$iPosition]);
	}

    /**
     * helper method to output the scheduling times as full cron expression.
     *
     * @return null|string 	Returns the cron expression or NULL if there is an error
     */
    public function __toString()
    {
        return (string) $this->getCronExpression();
    }
}