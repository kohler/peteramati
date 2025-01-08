<?php
// mailtemplate.php -- HotCRP mail templates
// HotCRP is Copyright (c) 2006-2019 Eddie Kohler and Regents of the UC
// See LICENSE for open-source distribution terms

global $mailTemplates;
$mailTemplates = array
    ("createaccount" =>
     array("subject" => "[%CONFSHORTNAME%] New account information",
	   "body" => "Greetings,

An account has been created for you at the %CONFNAME% submissions site, including an initial password.

        Site: %URL%/
       Email: %EMAIL%
    Password: %OPT(PASSWORD)%

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n"),

     "accountinfo" =>
     array("subject" => "[%CONFSHORTNAME%] Account information",
	   "body" => "Dear %NAME%,

Here is your account information for the %CONFNAME% submissions site.

        Site: %URL%/
       Email: %EMAIL%
    Password: %OPT(PASSWORD)%

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n"),

     "resetpassword" =>
     array("subject" => "[%CONFSHORTNAME%] Password reset request",
	   "body" => "Dear %NAME%,

We have received a request to reset the password for your account on the %CONFNAME% submissions site. If you made this request, please use the following link to create a new password. The link is only valid for 3 days from the time this email was sent.

%PASSWORDLINK%

If you did not make this request, please ignore this email.

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n"),

     "mergeaccount" =>
     array("subject" => "[%CONFSHORTNAME%] Merged account",
	   "body" => "Dear %NAME%,

Your account at the %CONFSHORTNAME% submissions site has been merged with the account of %OTHERCONTACT%. From now on, you should log in using the %OTHEREMAIL% account.

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n"),

     "requestreview" =>
     array("subject" => "[%CONFSHORTNAME%] Review request for paper #%NUMBER%",
	   "body" => "Dear %NAME%,

On behalf of the %CONFNAME% program committee, %OTHERCONTACT% would like to solicit your help with the review of %CONFNAME% paper #%NUMBER%.%IF(REASON)% They supplied this note: %REASON%%ENDIF%

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL(paper, p=%NUMBER%)%

If you are willing to review this paper, you may enter your review on the conference site or complete a review form offline and upload it.%IF(DEADLINE(extrev_soft))% Your review is requested by %DEADLINE(extrev_soft)%.%ENDIF%

Once you've decided, please take a moment to accept or decline this review request by using one of these links. You may also contact %OTHERNAME% directly or decline the review using the conference site.

      Accept: %URL(review, p=%NUMBER%&accept=1&%LOGINURLPARTS%)%
     Decline: %URL(review, p=%NUMBER%&decline=1&%LOGINURLPARTS%)%

For reference, your account information is as follows.

        Site: %URL%/
       Email: %EMAIL%
    Password: %OPT(PASSWORD)%

Or use the link below to sign in directly.

%LOGINURL%

Contact the site administrator, %ADMIN%, with any questions or concerns.

Thanks for your help -- we appreciate that reviewing is hard work!
- %CONFSHORTNAME% Submissions\n"),

     "retractrequest" =>
     array("subject" => "[%CONFSHORTNAME%] Retracting review request for paper #%NUMBER%",
	   "body" => "Dear %NAME%,

%OTHERNAME% has retracted a previous request that you review %CONFNAME% paper #%NUMBER%. There's no need to complete your review.

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%

Contact the site administrator, %ADMIN%, with any questions or concerns.

Thank you,
- %CONFSHORTNAME% Submissions\n"),

     "proposereview" =>
     array("subject" => "[%CONFSHORTNAME%] Proposed reviewer for paper #%NUMBER%",
	   "body" => "Greetings,

%OTHERCONTACT% would like %CONTACT3% to review %CONFNAME% paper #%NUMBER%.%IF(REASON)% They supplied this note: %REASON%%ENDIF%

Visit the assignment page to approve or deny the request.

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL(assign, p=%NUMBER%)%

- %CONFSHORTNAME% Submissions\n"),

     "denyreviewrequest" =>
     array("subject" => "[%CONFSHORTNAME%] Proposed reviewer for paper #%NUMBER% denied",
	   "body" => "Dear %NAME%,

Your proposal that %OTHERCONTACT% review %CONFNAME% paper #%NUMBER% has been denied by an administrator. You may want to propose someone else.

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL(paper, p=%NUMBER%)%

Contact the site administrator, %ADMIN%, with any questions or concerns.

Thank you,
- %CONFSHORTNAME% Submissions\n"),

     "refusereviewrequest" =>
     array("subject" => "[%CONFSHORTNAME%] Review request for paper #%NUMBER% declined",
	   "body" => "Dear %NAME%,

%OTHERCONTACT% cannot complete the review of %CONFNAME% paper #%NUMBER% that you requested. %IF(REASON)%They gave the following reason: %REASON% %ENDIF%You may want to find an alternate reviewer.

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL(paper, p=%NUMBER%)%

- %CONFSHORTNAME% Submissions\n"),

     "authorwithdraw" =>
     array("subject" => "[%CONFSHORTNAME%] Withdrawn paper #%NUMBER% %TITLEHINT%",
	   "body" => "Dear %NAME%,

An author of %CONFNAME% paper #%NUMBER% has withdrawn the paper from consideration. The paper will not be reviewed.%IF(REASON)% They gave the following reason: %REASON%%ENDIF%

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL(paper, p=%NUMBER%)%

Contact the site administrator, %ADMIN%, with any questions or concerns.

Thank you,
- %CONFSHORTNAME% Submissions\n"),

     "adminwithdraw" =>
     array("subject" => "[%CONFSHORTNAME%] Withdrawn paper #%NUMBER% %TITLEHINT%",
	   "body" => "Dear %NAME%,

%CONFNAME% paper #%NUMBER% has been withdrawn from consideration and will not be reviewed.

%IF(REASON)%The paper was withdrawn by an administrator, who provided the following reason: %REASON%%ELSE%The paper was withdrawn by an administrator.%ENDIF%

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL(paper, p=%NUMBER%)%

Contact the site administrator, %ADMIN%, with any questions or concerns.

Thank you,
- %CONFSHORTNAME% Submissions\n"),

     "withdrawreviewer" =>
     array("subject" => "[%CONFSHORTNAME%] Withdrawn paper #%NUMBER% %TITLEHINT%",
	   "body" => "Dear %NAME%,

%CONFSHORTNAME% paper #%NUMBER%, which you reviewed or have been assigned to review, has been withdrawn from consideration for the conference.

Authors and administrators can withdraw submissions during the review process.%IF(REASON)% The following reason was provided: %REASON%%ENDIF%

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL(paper, p=%NUMBER%)%

You are not expected to complete your review (and the system will not allow it unless the paper is revived).

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n"),

     "deletepaper" =>
     array("subject" => "[%CONFSHORTNAME%] Deleted paper #%NUMBER% %TITLEHINT%",
	   "body" => "Dear %NAME%,

Your %CONFNAME% paper #%NUMBER% has been removed from the submission database by an administrator. This can be done to remove duplicate papers. %IF(REASON)%The following reason was provided for deleting the paper: %REASON%%ENDIF%

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n"),

     "reviewsubmit" =>
     array("subject" => "[%CONFSHORTNAME%] Submitted review #%REVIEWNUMBER% %TITLEHINT%",
	   "body" => "Dear %NAME%,

Review #%REVIEWNUMBER% for %CONFNAME% paper #%NUMBER% has been submitted. The review is available at the paper site.

  Paper site: %URL(paper, p=%NUMBER%)%
       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
   Review by: %OPT(REVIEWAUTHOR)%

For the most up-to-date reviews and comments, or to unsubscribe from email notification, see the paper site.

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n"),

     "reviewupdate" =>
     array("subject" => "[%CONFSHORTNAME%] Updated review #%REVIEWNUMBER% %TITLEHINT%",
	   "body" => "Dear %NAME%,

Review #%REVIEWNUMBER% for %CONFNAME% paper #%NUMBER% has been updated. The review is available at the paper site.

  Paper site: %URL(paper, p=%NUMBER%)%
       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
   Review by: %OPT(REVIEWAUTHOR)%

For the most up-to-date reviews and comments, or to unsubscribe from email notification, see the paper site.

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n"),

     "commentnotify" =>
     array("subject" => "[%CONFSHORTNAME%] Comment for #%NUMBER% %TITLEHINT%",
	   "body" => "A comment for %CONFNAME% paper #%NUMBER% has been posted. For the most up-to-date comments, or to unsubscribe from email notification, see the paper site.

  Paper site: %URL(paper, p=%NUMBER%)%

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions

%COMMENTS%\n"),

     "responsenotify" =>
     array("subject" => "[%CONFSHORTNAME%] Response for #%NUMBER% %TITLEHINT%",
	   "body" => "The authors' response for %CONFNAME% paper #%NUMBER% is available as shown below. The authors may still update their response; for the most up-to-date version, or to turn off notification emails, see the paper site.

  Paper site: %URL(paper, p=%NUMBER%)%

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions

%COMMENTS%\n"),

     "finalsubmitnotify" =>
     array("subject" => "[%CONFSHORTNAME%] Updated final paper #%NUMBER% %TITLEHINT%",
	   "body" => "The final version for %CONFNAME% paper #%NUMBER% has been updated. The authors may still be able make updates; for the most up-to-date version, or to turn off notification emails, see the paper site.

  Paper site: %URL(paper, p=%NUMBER%)%

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n"),

     "genericmailtool" =>
     array("mailtool_name" => "Generic",
	   "mailtool_pc" => true,
	   "mailtool_priority" => 0,
	   "mailtool_recipients" => "s",
	   "subject" => "[%CONFSHORTNAME%] Paper #%NUMBER% %TITLEHINT%",
	   "body" => "Dear %NAME%,

Your message here.

       Title: %TITLE%
  Paper site: %URL(paper, p=%NUMBER%)%

Use the link below to sign in to the submissions site.

%LOGINURL%

Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n"),

     "brokenrepo" =>
     array("mailtool_name" => "Broken repository warning",
           "mailtool_recipients" => "none",
           "subject" => "[%CONFSHORTNAME%] Inaccessible %PSET% repository",
           "body" => "Dear %NAME%,

Your code repository for %PSET% appears to be broken. This usually means that you have not granted access to your repository to the cs61-staff team.

Please fix this problem or we won't be able to grade your work.

  Your grading server login: %LOGINURL%

  Your repo: %REPO%

Thanks,
- %CONFSHORTNAME% Staff"),

     "openrepo" =>
     array("mailtool_name" => "Open repository warning",
           "mailtool_recipients" => "none",
           "subject" => "[%CONFSHORTNAME%] Too-open %PSET% repository",
           "body" => "Dear %NAME%,

Your code repository for %PSET% appears to be too open. This usually means that you have not made your repository private and granted access to your repository to the cs61-staff team.

Please fix this problem or we may take off points.

  Your grading server login: %LOGINURL%

  Your repo: %REPO%

Thanks,
- %CONFSHORTNAME% Staff"),

     "missingrepo" =>
     array("mailtool_name" => "Missing repository warning",
           "mailtool_recipients" => "none",
           "subject" => "[%CONFSHORTNAME%] Missing %PSET% repository",
           "body" => "Dear %NAME%,

You haven't told us where to find your code for %PSET%. You need to log in to the grading server and enter your code.seas username and your code repository address. If you have a partner, you need to enter the same code repository as your partner.

Please fix this problem or we won't be able to grade your work.

  Your grading server login: %LOGINURL%

If you are having trouble, contact as at %ADMINEMAIL%.

Thanks,
- %CONFSHORTNAME% Staff"),

     "pregrade" =>
     array("mailtool_name" => "Pregrade notification",
           "mailtool_recipients" => "none",
           "subject" => "[%CONFSHORTNAME%] Checkin for %PSET%",
           "body" => "Dear %NAME%,

Here's the information we have about your %PSET%.

  Code repository: %REPO%
  Partner: %OPT(PARTNER)%
  Commit: %COMMIT%
  Commit date: %COMMITDATE%
  Late hours used on this pset: %LATEHOURS%

We will grade this commit unless you tell us otherwise. If you're still working, add a note to that effect to your README.txt and push that note to the repository. Don't forget to remove the note when your code is ready to grade. Remember that finishing a pset late is better than not finishing it at all! If you are having trouble, contact us at %SITEEMAIL%.

Thanks,
- %CONFSHORTNAME% Staff"),

     "registerpaper" =>
     array("subject" => "[%CONFSHORTNAME%] Registered paper #%NUMBER% %TITLEHINT%",
	   "body" => "Paper #%PAPER% has been registered at the %CONFNAME% submissions site.

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL(paper, p=%NUMBER%&%AUTHORVIEWCAPABILITY%)%

%NOTES%%IF(REASON)%An administrator provided the following reason for this registration: %REASON%

%ELSEIF(ADMINUPDATE)%An administrator performed this registration.

%ENDIF%Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n"),

     "updatepaper" =>
     array("subject" => "[%CONFSHORTNAME%] Updated paper #%NUMBER% %TITLEHINT%",
	   "body" => "Paper #%PAPER% has been updated at the %CONFNAME% submissions site.

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL(paper, p=%NUMBER%&%AUTHORVIEWCAPABILITY%)%

%NOTES%%IF(REASON)%An administrator provided the following reason for this update: %REASON%

%ELSEIF(ADMINUPDATE)%An administrator performed this update.

%ENDIF%Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n"),

     "submitpaper" =>
     array("subject" => "[%CONFSHORTNAME%] Submitted paper #%NUMBER% %TITLEHINT%",
	   "body" => "Paper #%PAPER% has been submitted to the %CONFNAME% submissions site.

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL(paper, p=%NUMBER%&%AUTHORVIEWCAPABILITY%)%

%NOTES%%IF(REASON)%An administrator provided the following reason for this update: %REASON%

%ELSEIF(ADMINUPDATE)%An administrator performed this update.

%ENDIF%Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n"),

     "submitfinalpaper" =>
     array("subject" => "[%CONFSHORTNAME%] Updated final paper #%NUMBER% %TITLEHINT%",
	   "body" => "The final version for paper #%PAPER% has been updated at the %CONFNAME% submissions site.

       Title: %TITLE%
     Authors: %OPT(AUTHORS)%
  Paper site: %URL(paper, p=%NUMBER%&%AUTHORVIEWCAPABILITY%)%

%NOTES%%IF(REASON)%An administrator provided the following reason for this update: %REASON%

%ELSEIF(ADMINUPDATE)%An administrator performed this update.

%ENDIF%Contact the site administrator, %ADMIN%, with any questions or concerns.

- %CONFSHORTNAME% Submissions\n")

);
