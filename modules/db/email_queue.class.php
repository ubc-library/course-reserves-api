<?php
    
    class EmailQueue {
        
        private $licr;
        private $db;
        
        function __construct ($licr)
        {
            $this->licr = $licr;
            $this->db   = $licr->db;
        }
        
        function enqueue ($recipients, $message_plain, $message_html)
        {
            $sql = "
    INSERT IGNORE INTO `email_queue`
    SET 
    `recipient`=:recipient,
    `message_plain`=:message_plain,
    `message_html`=:message_html,
    `message_rcpt_md5`=:message_rcpt_md5
    ";
            foreach ($recipients as $recipient) {
                if (trim ($recipient)) {
                    $message_rcpt_md5 = md5 (trim ($message_plain) . $recipient);
                    $this->db->execute ($sql, compact ('recipient', 'message_plain', 'message_html', 'message_rcpt_md5'));
                }
            }
        }
        
        function send ()
        {
            $sql     = "
      SELECT 
        GROUP_CONCAT(`email_id` SEPARATOR ',') as email_ids,
        `recipient`,
        GROUP_CONCAT(`message_plain` SEPARATOR '') AS plain,
        GROUP_CONCAT(`message_html` SEPARATOR '') AS html
      FROM `email_queue`
      WHERE `sent`=0
      GROUP BY `recipient`
    ";
            $res     = $this->db->execute ($sql);
            $success = [];
            require ('PHPMailer/class.phpmailer.php');
            while ($row = $res->fetch ()) {
                error_log ('EQ::send to ' . $row['recipient']);
                $limited_email = explode (',', SUBSCRIPTION_LIMIT_TO);
                if (!SUBSCRIPTION_LIMIT_TO || in_array ($row['recipient'], $limited_email)) {
                    $mail          = new PHPMailer ();
                    $mail->IsHTML  = (true);
                    $mail->CharSet = "text/html; charset=UTF-8;";
                    $mail->IsSMTP ();
                    $mail->WordWrap = 80;
                    $mail->Host     = SMTP_SERVER;
                    $mail->SMTPAuth = false;
                    $mail->From     = EMAIL_FROM_ADDRESS;
                    $mail->FromName = EMAIL_FROM_NAME;
                    $mail->Subject  = EMAIL_STATUS_CHANGE_SUBJECT;
                    $mail->AddAddress ($row['recipient']);
                    $mail->Body    = $row['html'];
                    $mail->AltBody = $row['plain'];
                    if (!$mail->Send ()) {
                        error_log ('Mail failed to send: ' . $mail->ErrorInfo);
                    } else {
                        error_log ("Sent to " . $row['recipient']);
                        $success[] = $row['email_ids'];
                    }
                } else {
                    echo "Skipped " . $row['recipient'] . " (not on debug recipients list)\n";
                }
            }
            if ($success) {
                $sql = "UPDATE `email_queue` SET `sent`=1 WHERE `email_id` IN(" . implode (',', $success) . ")";
                $this->db->execute ($sql);
            }
            
            return count ($success);
        }
    }
