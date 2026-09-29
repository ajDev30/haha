# Grading System in progress_dashboard.php

The `progress_dashboard.php` file manages two distinct grading and progress monitoring architectures: the **Moodle Official Graduation Logic** and the offline **ARAL Progress Monitor**.

---

## 1. Moodle Official Graduation Logic (Main Dashboard)

This is the system that powers the primary "Student Progress Comparison" table you see when you first open the dashboard. It relies on strict thresholds and official Moodle gradebook integration.

### Data Sources
*   **Pre-Assessment:** Taken from the external intake test (`readingassessment_ext_att`). The classification (e.g., Frustration) is pulled directly from the student's submitted profile.
*   **Post-Test Accuracy:** Taken from the student's *most recent* attempt recorded in the plugin (`readingassessment_post_att`) across the entire course.
*   **Post-Test Comprehension (Course Grade):** 
    *   The system attempts to fetch the student's overall **Course Total** from the Moodle gradebook (`grade_grade::fetch`).
    *   If the course total is available, it converts it to a percentage and uses it as the definitive Comprehension score.
    *   *Fallback:* If the Moodle course total is empty, it falls back to the comprehension score of their latest reading assessment attempt.

### Graduation Criteria
The system uses hard-coded constants to determine the student's status:
*   `GRAD_ACCURACY_THRESHOLD = 95%`
*   `GRAD_COMPREHENSION_THRESHOLD = 80%`

**Status Logic:**
1.  **✅ Graduated:** The student's latest accuracy is $\ge 95\%$ **AND** their Course Grade / Comprehension is $\ge 80\%$.
2.  **⚠️ Needs Practice:** The student has taken a post-test, but missed one or both of the thresholds.
3.  **🔄 No Post-Test Yet:** The student is enrolled but has no recorded post-test attempts and no course grade.
4.  **📋 Not Enrolled:** The student submitted a pre-assessment via the external link but has not registered a Moodle account matching that email address.

---

## 2. ARAL Progress Monitor (Teacher Review Details)

This is the custom, teacher-managed dashboard accessible by clicking **"View Details"** -> **"ARAL Progress Monitor"**. It acts as an offline tracker that does not affect Moodle's official gradebook.

### Data Architecture
This system ignores the Moodle gradebook completely and instead relies on two custom tables:
*   `readingassessment_aral_set`: Stores the student's pre-score override, pre-reading level, and the teacher's selected calculation formulas.
*   `readingassessment_aral_act`: Stores an unlimited number of manual "Post-Test Activities" logged by the teacher (e.g., Oral Reading, Quizzes).

### Dynamic Calculation Formulas
The teacher dictates how the final composite score is graded using the **Assessment Settings** panel.

**Composite Calculation Methods:**
*   **Equal Weight:** A simple average of all post-test activity percentages, regardless of how many items were in each activity.
*   **Pooled Score:** Calculates a unified percentage by dividing the total correct items across all activities by the total possible items.
*   **Teacher Weight:** Applies manual weighting. If weights aren't explicitly assigned by the teacher, it defaults to placing 60% of the weight on the *most recent* activity and distributing the remaining 40% across older activities.

**Progress / Gain Methods:**
*   **Percentage-Point Gain:** Subtracts the Pre-Score directly from the Post-Test Composite (`Post - Pre`).
*   **Relative Improvement:** Measures the proportional growth relative to the starting score `((Post - Pre) / Pre) * 100`.

### ARAL Reading Levels and Status
The final composite percentage dictates the student's post-reading level using DepEd ARAL thresholds:
*   **< 90%:** Frustration
*   **90% - 96%:** Instructional
*   **97% - 100%:** Independent (Grade-Ready)

The system compares the Post-Reading Level against the teacher-assigned Pre-Reading level to calculate the **Movement** (Improved, Maintained, Regressed) and the final **ARAL Status** (e.g., *Continuing ARAL – Below Grade Level*, or *Grade-Ready / Independent (Exit ARAL)*).
