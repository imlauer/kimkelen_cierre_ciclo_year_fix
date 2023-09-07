<?php
/*
 * Kimkëlen - School Management Software
 * Copyright (C) 2013 CeSPI - UNLP <desarrollo@cespi.unlp.edu.ar>
 *
 * This file is part of Kimkëlen.
 *
 * Kimkëlen is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License v2.0 as published by
 * the Free Software Foundation.
 *
 * Kimkëlen is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Kimkëlen.  If not, see <http://www.gnu.org/licenses/gpl-2.0.html>.
 */

class NacionalAnalyticalBehaviour extends DefaultAnalyticalBehaviour
{
    public function getApprovationDateBySubject($approvationInstance)
    {
        switch(get_class($approvationInstance)) {
          case 'StudentApprovedCourseSubject':
            //return November/July
                $period = $approvationInstance->getCourseSubject()->getLastCareerSchoolYearPeriod();
                if(!is_null($period))
                {
                  return $period->getEndAt();
                }
                break;
                
          case 'StudentDisapprovedCourseSubject': 
              //return December/February
            $cssid = $approvationInstance->getCourseSubjectStudentId();
            $csse = CourseSubjectStudentExaminationPeer::retrieveLastByCourseSubjectStudentId($cssid);
            $exam = $csse->getExaminationSubject()->getExamination();
            if ($csse->getDate())
            {
                return $csse->getDate();
            }else
            {
                return ($csse->getExaminationSubject()->getDate()) ? $csse->getExaminationSubject()->getDate() : $exam->getDateFrom();
            }
          case 'StudentRepprovedCourseSubject':
               
            $sers = StudentExaminationRepprovedSubjectPeer::retrieveByStudentRepprovedCourseSubject($approvationInstance); 
            if(is_null($sers->getExaminationRepprovedSubject()))
            {
                //Estuvo en trayectorias. Es el año de la trayectoria + 1
                $cssp = CourseSubjectStudentPathwayPeer::retrieveByCourseSubjectStudent($approvationInstance->getCourseSubjectStudent());
                /*$year = $cssp->getPathwayStudent()->getPathway()->getSchoolYear()->getYear();
                $year += 1;
                return $year .'-07-01';*/
                return $cssp->getCourseSubject()->getCourse()->getEvaluationDate();
                
            }
            else
            {
                $exam = $sers->getExaminationRepprovedSubject()->getExaminationRepproved();
                return ($sers->getExaminationRepprovedSubject()->getDate()) ? $sers->getExaminationRepprovedSubject()->getDate() : $exam->getDateFrom(); 
            }
             
            
        }

        //couldn't find when was approved. return null ¿error?
        return;
        
    }
    
    protected function add_subject_to_year($year, $css)
    {
        if (!isset($this->objects[$year]))
        {
            $this->objects[$year] = array();
            $this->objects[$year]['subjects'] = array();
        }
        $this->objects[$year]['subjects'][] = $css;
        
        if(! is_null($css->getApproved()) && $css->getApproved()->getCareerSubject()->getId() == CareerSubject::TALLER_S_NACIO)
        {
            $this->approved_subject = TRUE;
        }
    }

    public function process()
    {
        $this->student_career_school_years = $this->get_student()->getStudentCareerSchoolYearsOrderByYear();
	$scsy_cursed = $this->get_student()->getLastStudentCareerSchoolYearCoursed();	

        //Deberia recorrer todos los "scsy" y recuperar por c/año las materias
        $this->init();
        $avg_mark_for_year = array();

        foreach ($this->student_career_school_years as $scsy)
        {
            //Si está en el arreglo de estados válidos o está retirado y cursó materias en ese año o si repitio ero fue el ultimo año que cursó
            if (in_array($scsy->getStatus(), $this->valid_status) || ($scsy->getStatus() == StudentCareerSchoolYearStatus::WITHDRAWN  && 
                         $scsy->getId() == $scsy_cursed->getId()) || ($scsy->getStatus() == StudentCareerSchoolYearStatus::REPPROVED && 
                         $scsy->getId() == $scsy_cursed->getId()) )
            {
                $year_in_career = $scsy->getYear(); 
                $this->add_year_in_career($year_in_career);
                $career_school_year = $scsy->getCareerSchoolYear();
                $school_year = $career_school_year->getSchoolYear();

                $approved = StudentApprovedCareerSubjectPeer::retrieveByStudentAndSchoolYear($this->get_student(), $school_year);
                $csss = SchoolBehaviourFactory::getInstance()->getCourseSubjectStudentsForAnalytics($this->get_student(), $school_year, $scsy);
				
                foreach ($csss as $css)
                {	
                    if (!isset($this->objects[$year_in_career]))
                    {
                        // Inicialización por año
                        $this->set_year_status($year_in_career, self::YEAR_COMPLETE);
                        $avg_mark_for_year[$year_in_career]['sum'] = 0;
                        $avg_mark_for_year[$year_in_career]['count'] = 0;
                    }
                    
                    if (!$css->getCourseSubjectStudent()->getIsNotAverageable())
                    {
                        $avg_mark_for_year[$year_in_career]['sum'] += $css->getMark();
                        $avg_mark_for_year[$year_in_career]['count'] += ($css->getMark(false) ? 1 : 0);
                        if (!$css->getMark(false))
                        {
                            // No tiene nota -> el curso está incompleto
                            $this->set_year_status($year_in_career, self::YEAR_INCOMPLETE);
                            $this->add_missing_subject($css);
                        }
                    }
                    
                    if($css->getCourseSubjectStudent()->getIsNotAverageable() && $css->getCourseSubjectStudent()->getNotAverageableCalification() == NotAverageableCalificationType::DISAPPROVED && !$css->getMark(false))
                    {
                        $this->set_year_status($year_in_career, self::YEAR_INCOMPLETE);
                            $this->add_missing_subject($css);
                    }
                   
                    $this->add_subject_to_year($year_in_career, $css);
                    $this->check_last_exam_date($css->getApprovedDate(false));
                }

                // Cálculo del promedio por año
                if($school_year->getYear() != 2020)
                {
                  foreach ($this->objects as $year => $data)
                  {
                      $this->process_year_average($year, $avg_mark_for_year[$year]['sum'], $avg_mark_for_year[$year]['count']);
                  }
                  $this->process_total_average($avg_mark_for_year);
                }               
            }            
        }
    }

	
}
