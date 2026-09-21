<?php

return [
    // Kërkesë rezervimi e marrë (klienti)
    'booking_received' => [
        'subject' => 'Kërkesë Rezervimi e Marrë — :reference',
        'greeting' => 'Përshëndetje :name,',
        'intro' => 'Kemi marrë kërkesën tuaj për rezervim. Operatori do ta shqyrtojë dhe konfirmojë së shpejti.',
        'reference_label' => 'Referenca',
        'vehicle_label' => 'Automjeti',
        'dates_label' => 'Datat',
        'total_label' => 'Totali',
        'cancel_action' => 'Anulo Rezervimin',
        'cancel_note' => 'Mund ta anuloni këtë rezervim në çdo kohë para se të fillojë qiraja, duke përdorur lidhjen e mësipërme.',
        'outro' => 'Faleminderit që zgjodhët :operator.',
    ],

    // Njoftim rezervimi i ri (operatori)
    'new_booking_alert' => [
        'subject' => 'Kërkesë e Re Rezervimi — :reference',
        'bell_title' => 'Kërkesë e Re Rezervimi',
        'greeting' => 'Përshëndetje,',
        'intro' => 'Është paraqitur një kërkesë e re rezervimi.',
        'customer_label' => 'Klienti',
        'vehicle_label' => 'Automjeti',
        'dates_label' => 'Datat',
        'total_label' => 'Totali',
        'view_action' => 'Shiko Rezervimin',
        'outro' => 'Hyni në panelin tuaj për të konfirmuar ose refuzuar këtë rezervim.',
    ],

    // Rezervimi konfirmuar (klienti)
    'booking_confirmed' => [
        'subject' => 'Rezervimi Konfirmuar — :reference',
        'greeting' => 'Përshëndetje :name,',
        'intro' => 'Lajme të mira — rezervimi juaj është konfirmuar!',
        'reference_label' => 'Referenca',
        'vehicle_label' => 'Automjeti',
        'dates_label' => 'Datat',
        'total_label' => 'Totali',
        'payment_note' => 'Pagesa bëhet gjatë marrjes — ju lutemi rregullojeni me operatorin.',
        'agreement_button' => 'Shkarko Kontratën',
        'cancel_action' => 'Anulo Rezervimin',
        'cancel_note' => 'Duhet të ndryshoni planet? Mund ta anuloni në çdo kohë para se të fillojë qiraja, duke përdorur lidhjen e mësipërme.',
        'outro' => 'Faleminderit që rezervuat me :operator.',
    ],

    // Rezervimi refuzuar (klienti)
    'booking_rejected' => [
        'subject' => 'Informacion mbi Rezervimin — :reference',
        'greeting' => 'Përshëndetje :name,',
        'intro' => 'Fatkeqësisht, operatori nuk mundi të konfirmojë kërkesën tuaj për rezervim.',
        'reference_label' => 'Referenca',
        'vehicle_label' => 'Automjeti',
        'dates_label' => 'Datat',
        'reason_label' => 'Arsyeja',
        'outro' => 'Ju lutemi provoni data të tjera ose kontaktoni drejtpërdrejt :operator.',
    ],

    // Rezervimi anuluar (klienti ose operatori)
    'booking_moved' => [
        'subject' => 'Rezervimi u Përditësua — :reference',
        'greeting' => 'Përshëndetje :name,',
        'intro' => 'Datat e rezervimit tuaj kanë ndryshuar.',
        'reference_label' => 'Referenca',
        'previous_label' => 'Më parë',
        'new_label' => 'Tani',
        'vehicle_label' => 'Automjeti',
        'dates_label' => 'Datat',
        'outro' => 'Nëse keni pyetje, ju lutemi kontaktoni :operator.',
    ],

    'booking_cancelled' => [
        'subject' => 'Rezervimi Anuluar — :reference',
        'bell_title' => 'Rezervimi Anuluar nga Klienti',
        'greeting' => 'Përshëndetje :name,',
        'intro' => 'Rezervimi juaj është anuluar.',
        'reference_label' => 'Referenca',
        'vehicle_label' => 'Automjeti',
        'dates_label' => 'Datat',
        'reason_label' => 'Arsyeja',
        // Shkruhet në bookings.cancellation_reason nga fshirja automatike dhe
        // shfaqet si rreshti i arsyes në këtë email.
        'expired_reason' => 'Nuk u konfirmua në kohë nga kompania e qirasë',
        'outro' => 'Nëse nuk e keni kërkuar këtë anulim, ju lutemi kontaktoni :operator.',
    ],

    // Kujtesa e rinovimit të abonimit (operatori, faturim manual B2B)
    'subscription_renewal_reminder' => [
        'subject' => 'Abonimi juaj rinovohet pas :days dite(sh)',
        'greeting' => 'Përshëndetje :name,',
        'intro' => 'Periudha e abonimit tuaj përfundon pas :days dite(sh), më :date.',
        'plan_label' => 'Plani',
        'paid_until_label' => 'Paguar deri më',
        'payment_note' => 'Për ta mbajtur aktive faqen tuaj të rezervimeve dhe panelin, ju lutemi kryeni pagesën me transfertë bankare ose personalisht para përfundimit të periudhës.',
        'outro' => 'Nëse tashmë keni paguar, mund ta injoroni këtë mesazh — pagesa juaj do të regjistrohet së shpejti.',
    ],

    // Kujtesa e përfundimit të provës falas (operatori, periudha e parë falas)
    'trial_expiring_reminder' => [
        'subject' => 'Prova juaj falas përfundon pas :days dite(sh)',
        'greeting' => 'Përshëndetje :name,',
        'intro' => 'Prova juaj falas përfundon pas :days dite(sh), më :date.',
        'plan_label' => 'Plani',
        'paid_until_label' => 'Prova përfundon',
        'payment_note' => 'Për ta mbajtur aktive faqen tuaj të rezervimeve dhe panelin pas provës, ju lutemi zgjidhni një plan dhe kryeni pagesën me transfertë bankare ose personalisht.',
        'outro' => 'Nëse tashmë keni paguar, mund ta injoroni këtë mesazh — pagesa juaj do të regjistrohet së shpejti.',
    ],

    // Dërgohet një ditë pas përfundimit të periudhës — paralajmërimi i fundit para pezullimit.
    'trial_grace_reminder' => [
        'subject' => 'Prova juaj falas ka përfunduar — edhe :days ditë',
        'greeting' => 'Përshëndetje :name,',
        'intro' => 'Prova juaj falas përfundoi më :date. Ju kanë mbetur edhe :days ditë për të zgjedhur një plan dhe për të kryer pagesën — pas :suspends_on faqja juaj e rezervimeve dhe paneli do të pezullohen.',
        'plan_label' => 'Plani',
        'paid_until_label' => 'Prova përfundoi',
        'payment_note' => 'Për ta mbajtur aktive faqen tuaj të rezervimeve dhe panelin, ju lutemi zgjidhni një plan dhe kryeni pagesën me transfertë bankare ose personalisht.',
        'outro' => 'Nëse tashmë keni paguar, mund ta injoroni këtë mesazh — pagesa juaj do të regjistrohet së shpejti.',
    ],

    'subscription_grace_reminder' => [
        'subject' => 'Abonimi juaj ka skaduar — edhe :days ditë',
        'greeting' => 'Përshëndetje :name,',
        'intro' => 'Periudha e abonimit tuaj përfundoi më :date. Ju kanë mbetur edhe :days ditë për ta rinovuar — pas :suspends_on faqja juaj e rezervimeve dhe paneli do të pezullohen.',
        'plan_label' => 'Plani',
        'paid_until_label' => 'Paguar deri më',
        'payment_note' => 'Për ta mbajtur aktive faqen tuaj të rezervimeve dhe panelin, ju lutemi kryeni pagesën me transfertë bankare ose personalisht.',
        'outro' => 'Nëse tashmë keni paguar, mund ta injoroni këtë mesazh — pagesa juaj do të regjistrohet së shpejti.',
    ],

    // Kujtesa e servisit (operatori, gjurmimi i mirëmbajtjes së automjeteve)
    'service_due' => [
        'subject' => 'Servisi afër afatit — :vehicle',
        'greeting' => 'Përshëndetje,',
        'intro' => 'Automjeti :vehicle ka një servis të planifikuar më :date.',
        'vehicle_label' => 'Automjeti',
        'due_on_label' => 'Afati',
        'last_service_label' => 'Servisi i fundit',
        'outro' => 'Regjistroni një servis të ri sapo të kryhet për ta pastruar këtë kujtesë.',
    ],

    // Kërkesa për vlerësim
    'review_request' => [
        'subject' => 'Si ishte qiraja juaj me :operator?',
        'greeting' => 'Përshëndetje :name,',
        'intro' => 'Faleminderit që morët me qira :vehicle. Do të donim të dëgjonim përvojën tuaj — merr vetëm një minutë.',
        'button' => 'Lini një vlerësim',
        'outro' => 'Faleminderit që zgjodhët :operator.',
    ],
    // Njoftim kur lirohen datat e pritjes
    'waitlist_slot_open' => [
        'subject' => 'Datat tuaja sapo u liruan te :operator',
        'greeting' => 'Përshëndetje :name,',
        'intro' => 'Lajm i mirë — :vehicle është tani i lirë nga :start deri më :end, datat që kërkuat.',
        'button' => 'Rezervo këto data',
        'no_hold' => 'Nuk e kemi rezervuar për ju — automjetin e merr kush e rezervon i pari, prandaj mos prisni gjatë.',
        'outro' => 'Faleminderit që zgjodhët :operator.',
    ],
    // Njoftim kur automjeti kthehet në dispozicion
    'vehicle_back_in_stock' => [
        'subject' => ':vehicle është sërish në dispozicion te :operator',
        'greeting' => 'Përshëndetje :name,',
        'intro' => 'Lajm i mirë — :vehicle është kthyer në rrugë dhe gati për rezervim.',
        'button' => 'Shiko automjetin',
        'no_hold' => 'Të gjithë ata që pyetën për këtë automjet janë njoftuar dhe nuk e kemi rezervuar për askënd — e merr kush e rezervon i pari.',
        'outro' => 'Faleminderit që zgjodhët :operator.',
    ],
];
