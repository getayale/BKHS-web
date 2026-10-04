<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$supportedLanguages = [
    'en' => 'English',
    'am' => 'አማርኛ',
];

$requestedLanguage = $_GET['lang'] ?? null;

if (
    is_string($requestedLanguage) &&
    array_key_exists($requestedLanguage, $supportedLanguages)
) {
    $_SESSION['language'] = $requestedLanguage;
}

$currentLanguage = $_SESSION['language'] ?? 'en';

if (!array_key_exists($currentLanguage, $supportedLanguages)) {
    $currentLanguage = 'en';
    $_SESSION['language'] = 'en';
}

$translations = [

    'en' => [

        'home' => 'Home',
        'about' => 'About',
        'admission' => 'Admission',
        'announcement' => 'Announcement',
        'gallery' => 'Gallery',
        'contact' => 'Contact',
        'login' => 'Login',
        'school' => 'School',

        'welcome_to_bkhs' => 'Welcome to BKHS',

        'building_a' => 'Building a',
        'brighter_future' => 'Brighter Future',
        'through_education' => 'Through Education',

        'hero_description' =>
            'Bole Kale Hiwot School is committed to creating a supportive learning environment where students develop knowledge, character, creativity, and confidence for the future.',

        'apply_for_admission' => 'Apply for Admission',
        'discover_our_school' => 'Discover Our School',

        'student_centered_learning' => 'Student-centered learning',
        'supportive_environment' => 'Supportive environment',

        'education_character_excellence' =>
            'Education • Character • Excellence',

        'quality_education' => 'Quality Education',
        'learning_for_life' => 'Learning for life',

        'student_growth' => 'Student Growth',
        'learn_grow_together' => 'Learn & grow together',

        'quality_education_description' =>
            'Creating meaningful learning experiences that help students build strong foundations.',

        'dedicated_teachers' => 'Dedicated Teachers',

        'dedicated_teachers_description' =>
            'Supporting students through guidance, encouragement, and effective teaching.',

        'student_development' => 'Student Development',

        'student_development_description' =>
            'Encouraging academic, personal, social, and creative development.',

        'about_our_school' => 'ABOUT OUR SCHOOL',

        'education_beyond_classroom' =>
            'Education that goes beyond the classroom',

        'about_description' =>
            'At Bole Kale Hiwot School, we believe education is about more than academic achievement. We aim to create an environment where students can learn, explore, communicate, and develop the confidence they need for their future.',

        'supportive_welcoming_environment' =>
            'A supportive and welcoming learning environment',

        'academic_personal_development' =>
            'Focus on academic and personal development',

        'creativity_responsibility' =>
            'Encouragement of creativity and responsibility',

        'learn' => 'Learn',
        'discover_new_possibilities' => 'Discover new possibilities',

        'learn_more_about_bkhs' => 'Learn more about BKHS',

        'admissions' => 'ADMISSIONS',

        'strong_foundation_tomorrow' =>
            'Give your child a strong foundation for tomorrow.',

        'admission_description' =>
            'Learn more about our admission process, requirements, and how to apply.',

        'view_admission_information' =>
            'View Admission Information',

        'stay_informed' => 'STAY INFORMED',

        'latest_announcements' => 'Latest Announcements',

        'announcements_description' =>
            'Stay updated with the latest school news, events, and important information.',

        'school_news' => 'School News',

        'welcome_school_community' =>
            'Welcome to the BKHS School Community',

        'school_community_description' =>
            'Discover the latest updates and activities from our school community.',

        'academic' => 'Academic',

        'academic_activities_updates' =>
            'Academic Activities and Updates',

        'academic_information_description' =>
            'Important academic information for students and parents.',

        'event' => 'Event',

        'school_events_activities' =>
            'School Events and Activities',

        'school_events_description' =>
            'Explore upcoming school activities and community events.',

        'read_more' => 'Read more',

        'view_all_announcements' =>
            'View All Announcements',

        'school_life' => 'SCHOOL LIFE',

        'explore_gallery' => 'Explore our Gallery',

        'gallery_description' =>
            'A glimpse into learning, activities, events, and school life.',

        'learning' => 'Learning',
        'activities' => 'Activities',
        'students' => 'Students',
        'events' => 'Events',

        'view_full_gallery' => 'View Full Gallery',

        'contact_us' => 'CONTACT US',

        'wed_love_to_hear' => "We'd love to hear from you",

        'contact_description' =>
            'Have a question about admissions, school activities, or anything else? Get in touch with us.',

        'visit_us' => 'Visit Us',
        'bole_addis_ababa_ethiopia' => 'Bole, Addis Ababa, Ethiopia',

        'call_us' => 'Call Us',
        'email_us' => 'Email Us',

        'your_name' => 'Your Name',
        'enter_your_name' => 'Enter your name',

        'email_address' => 'Email Address',
        'enter_your_email' => 'Enter your email',

        'subject' => 'Subject',
        'how_can_we_help' => 'How can we help?',

        'message' => 'Message',
        'write_your_message' => 'Write your message...',

        'send_message' => 'Send Message',

        'quick_links' => 'Quick Links',
        'information' => 'Information',
        'connect_with_us' => 'Connect With Us',

        'all_rights_reserved' => 'All rights reserved.',

        'bkhs_school_management_system' =>
            'BKHS School Management System',

        'facebook' => 'Facebook',
        'telegram' => 'Telegram',
        'instagram' => 'Instagram',
        'youtube' => 'YouTube',

        'january' => 'JAN',

    ],

    'am' => [

        'home' => 'መነሻ',
        'about' => 'ስለ እኛ',
        'admission' => 'የትምህርት ቤት መግቢያ',
        'announcement' => 'ማስታወቂያ',
        'gallery' => 'ፎቶ ጋለሪ',
        'contact' => 'ያግኙን',
        'login' => 'ግባ',
        'school' => 'ትምህርት ቤት',

        'welcome_to_bkhs' => 'ወደ BKHS እንኳን በደህና መጡ',

        'building_a' => 'በመገንባት ላይ',
        'brighter_future' => 'የተሻለ የወደፊት ሕይወት',
        'through_education' => 'በትምህርት',

        'hero_description' =>
            'ቦሌ ቃለ ሕይወት ትምህርት ቤት ተማሪዎች እውቀት፣ ባህሪ፣ ፈጠራ እና ለወደፊት የሚያስፈልጋቸውን በራስ መተማመን እንዲያዳብሩ ደጋፊ የትምህርት አካባቢ ለመፍጠር ቁርጠኛ ነው።',

        'apply_for_admission' => 'ለመግቢያ ያመልክቱ',
        'discover_our_school' => 'ትምህርት ቤታችንን ይወቁ',

        'student_centered_learning' => 'ተማሪን ማዕከል ያደረገ ትምህርት',
        'supportive_environment' => 'ደጋፊ የትምህርት አካባቢ',

        'education_character_excellence' =>
            'ትምህርት • ባህሪ • የላቀ ውጤት',

        'quality_education' => 'ጥራት ያለው ትምህርት',
        'learning_for_life' => 'ለሕይወት መማር',

        'student_growth' => 'የተማሪ እድገት',
        'learn_grow_together' => 'አብረን እንማር እና እናድግ',

        'quality_education_description' =>
            'ተማሪዎች ጠንካራ መሠረት እንዲገነቡ የሚረዱ አስፈላጊ የመማሪያ ልምዶችን መፍጠር።',

        'dedicated_teachers' => 'ቁርጠኛ መምህራን',

        'dedicated_teachers_description' =>
            'በመመሪያ፣ በማበረታቻ እና ውጤታማ በሆነ አስተማሪነት ተማሪዎችን መደገፍ።',

        'student_development' => 'የተማሪ እድገት',

        'student_development_description' =>
            'የትምህርት፣ የግል፣ የማህበራዊ እና የፈጠራ እድገትን ማበረታታት።',

        'about_our_school' => 'ስለ ትምህርት ቤታችን',

        'education_beyond_classroom' =>
            'ከክፍል ውስጥ ትምህርት በላይ',

        'about_description' =>
            'በቦሌ ቃለ ሕይወት ትምህርት ቤት ትምህርት ማለት ከትምህርታዊ ውጤት በላይ እንደሆነ እናምናለን። ተማሪዎች እንዲማሩ፣ እንዲመራመሩ፣ እንዲግባቡ እና ለወደፊታቸው የሚያስፈልጋቸውን በራስ መተማመን እንዲያዳብሩ አካባቢ ለመፍጠር እንጥራለን።',

        'supportive_welcoming_environment' =>
            'ደጋፊ እና አቀባበል ያለው የመማሪያ አካባቢ',

        'academic_personal_development' =>
            'በትምህርታዊ እና በግል እድገት ላይ ትኩረት',

        'creativity_responsibility' =>
            'ፈጠራን እና ኃላፊነትን ማበረታታት',

        'learn' => 'ተማር',
        'discover_new_possibilities' => 'አዳዲስ እድሎችን ያግኙ',

        'learn_more_about_bkhs' => 'ስለ BKHS ተጨማሪ ይወቁ',

        'admissions' => 'የመግቢያ መረጃ',

        'strong_foundation_tomorrow' =>
            'ለልጅዎ ለነገ ጠንካራ መሠረት ይገንቡ።',

        'admission_description' =>
            'ስለ የመግቢያ ሂደታችን፣ መስፈርቶች እና እንዴት ማመልከት እንደሚችሉ ተጨማሪ ይወቁ።',

        'view_admission_information' =>
            'የመግቢያ መረጃን ይመልከቱ',

        'stay_informed' => 'መረጃ ይከታተሉ',

        'latest_announcements' => 'የቅርብ ጊዜ ማስታወቂያዎች',

        'announcements_description' =>
            'የቅርብ ጊዜ የትምህርት ቤት ዜናዎችን፣ ዝግጅቶችን እና አስፈላጊ መረጃዎችን ይከታተሉ።',

        'school_news' => 'የትምህርት ቤት ዜና',

        'welcome_school_community' =>
            'ወደ BKHS የትምህርት ቤት ማህበረሰብ እንኳን በደህና መጡ',

        'school_community_description' =>
            'ከትምህርት ቤታችን ማህበረሰብ የሚመጡ የቅርብ ጊዜ ዝመናዎችን እና እንቅስቃሴዎችን ይወቁ።',

        'academic' => 'ትምህርታዊ',

        'academic_activities_updates' =>
            'የትምህርት እንቅስቃሴዎች እና ዝመናዎች',

        'academic_information_description' =>
            'ለተማሪዎች እና ለወላጆች አስፈላጊ የትምህርት መረጃ።',

        'event' => 'ዝግጅት',

        'school_events_activities' =>
            'የትምህርት ቤት ዝግጅቶች እና እንቅስቃሴዎች',

        'school_events_description' =>
            'መጪ የትምህርት ቤት እንቅስቃሴዎችን እና የማህበረሰብ ዝግጅቶችን ይመልከቱ።',

        'read_more' => 'ተጨማሪ ያንብቡ',

        'view_all_announcements' =>
            'ሁሉንም ማስታወቂያዎች ይመልከቱ',

        'school_life' => 'የትምህርት ቤት ሕይወት',

        'explore_gallery' => 'የፎቶ ጋለሪያችንን ይመልከቱ',

        'gallery_description' =>
            'የመማር፣ የእንቅስቃሴዎች፣ የዝግጅቶች እና የትምህርት ቤት ሕይወት አጭር ምልክት።',

        'learning' => 'መማር',
        'activities' => 'እንቅስቃሴዎች',
        'students' => 'ተማሪዎች',
        'events' => 'ዝግጅቶች',

        'view_full_gallery' => 'ሙሉ ጋለሪውን ይመልከቱ',

        'contact_us' => 'ያግኙን',

        'wed_love_to_hear' => 'ከእርስዎ መስማት እንወዳለን',

        'contact_description' =>
            'ስለ መግቢያ፣ የትምህርት ቤት እንቅስቃሴ ወይም ሌላ ጥያቄ ካለዎት ያግኙን።',

        'visit_us' => 'ይጎብኙን',
        'bole_addis_ababa_ethiopia' => 'ቦሌ፣ አዲስ አበባ፣ ኢትዮጵያ',

        'call_us' => 'ይደውሉልን',
        'email_us' => 'ኢሜይል ይላኩልን',

        'your_name' => 'ስምዎ',
        'enter_your_name' => 'ስምዎን ያስገቡ',

        'email_address' => 'የኢሜይል አድራሻ',
        'enter_your_email' => 'ኢሜይልዎን ያስገቡ',

        'subject' => 'ርዕስ',
        'how_can_we_help' => 'እንዴት ልንረዳዎት እንችላለን?',

        'message' => 'መልዕክት',
        'write_your_message' => 'መልዕክትዎን ይጻፉ...',

        'send_message' => 'መልዕክት ላክ',

        'quick_links' => 'ፈጣን አገናኞች',
        'information' => 'መረጃ',
        'connect_with_us' => 'ያግኙን',

        'all_rights_reserved' => 'መብቱ በሙሉ የተጠበቀ ነው።',

        'bkhs_school_management_system' =>
            'የBKHS የትምህርት ቤት አስተዳደር ስርዓት',

        'facebook' => 'Facebook',
        'telegram' => 'Telegram',
        'instagram' => 'Instagram',
        'youtube' => 'YouTube',

        'january' => 'ጃን',

    ],
];

function __(string $key): string
{
    global $translations, $currentLanguage;

    if (
        isset($translations[$currentLanguage][$key]) &&
        is_string($translations[$currentLanguage][$key])
    ) {
        return $translations[$currentLanguage][$key];
    }

    if (
        isset($translations['en'][$key]) &&
        is_string($translations['en'][$key])
    ) {
        return $translations['en'][$key];
    }

    return $key;
}

function currentLanguage(): string
{
    global $currentLanguage;

    return $currentLanguage;
}

function languageName(string $language): string
{
    global $supportedLanguages;

    return $supportedLanguages[$language] ?? 'English';
}

function languageUrl(string $language): string
{
    $path = $_SERVER['PHP_SELF'] ?? 'index.php';

    return htmlspecialchars(
        $path . '?lang=' . urlencode($language),
        ENT_QUOTES,
        'UTF-8'
    );
}