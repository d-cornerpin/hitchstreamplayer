<?php
/*
Template Name: Player - Venue Show
*/
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>

<head>
    <meta charset="<?php bloginfo("charset"); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=.75, user-scalable=no">
    <?php if (is_singular() && pings_open(get_queried_object())) { ?>
    <link rel="pingback" href="<?php bloginfo("pingback_url"); ?>">
    <?php } ?>
    <?php wp_head(); ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lato&family=Playfair+Display:wght@500&display=swap" rel="stylesheet">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>


    <style>
        .hs_presentedby {
            background-image: url('https://hitchstream.com/wp-content/uploads/2023/04/Presented-By.png');
            background-size: cover;
            background-position: center;
            width: 425px;
            height: 181px;
            position: fixed;
            bottom: 30px;
            left: 5px;
            z-index: 9999;
        }

        .hs_presentedby_link {
            display: inline-block;
            width: 425px;
            height: 0;
        }


        /* Hidden Element Class*/
        .hideme {
            display: none;
        }

        /* Hidden Element Class*/
        #hs_player {
            display: none;
        }

        /* Body and Background Styling */
        .hs_body {
            background-position: center;
            background-size: cover;
        }

        .hs_width {
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
            border-radius: 30px;
            padding-top: 40px;
        }

        .hs_container {
            height: 100vh;
            overflow: hidden;
            background: black;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background-position: top center;
            background-size: cover;
            background-blend-mode: screen, normal;
        }

        .hs_blur {
            backdrop-filter: brightness(50%) saturate(110%) contrast(110%) blur(3px);
            -webkit-backdrop-filter: brightness(50%) saturate(110%) contrast(110%) blur(3px);
            width: 100%;
            height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        /* End Body and Background Styling*/

        /*Before Wedding Text Styling */
        .hs_beforetextcont {
            max-width: 635px;
            margin: 0 auto;
        }

        .hs_beforetext {
            text-align: center;
            font-size: 30px;
            font-family: 'Lato', sans-serif;
            font-weight: 300;
            white-space: nowrap;
        }

        /*End Before Wedding Text Styling */

        /*After Wedding Starts Text Styling */
        .hs_aftertextcont {
            max-width: 635px;
            margin: 0 auto;
        }

        .hs_aftertext {
            text-align: center;
            font-size: 30px;
            font-family: 'Lato', sans-serif;
            font-weight: 300;
            white-space: nowrap;
        }

        /*End After Wedding Starts Text Styling */

        /*Time and Date Styling*/
        .hs_dateandtime {
            text-align: center;
            /*            padding-top: 30px;*/
            font-family: 'Lato', sans-serif;
            font-weight: 200;
            font-size: 30px;
        }

        /*End Time and Date Styling*/

        /*Venue Styling*/
        .hs_venue {
            text-align: center;
            font-family: 'Lato', sans-serif;
            font-weight: 200;
            font-size: 30px;
        }

        .hs_venue a {
            color: inherit;
            text-decoration: none;
        }

        /*Venue Styling*/

        /* Player Styling */
        .hs_playercontainer {
            width: 100%;
            margin: 0 auto;
            /*            height: 361px;*/
            padding-top: 30px;
        }

        .hs_playerbox {
            width: 635px;
            /*height: 337px;*/
            margin: 0 auto;
        }

        /* End Player Styling */

        /* Social Icon Styling */
        .hs_socials {
            text-align: center;
            padding-bottom: 30px;
            /*            opacity: 0.7;*/
        }

        .hsIco {
            font-size: 40px;
            margin-left: .25em;
            margin-right: .25em;
            display: inline-block;
            vertical-align: middle;
            -webkit-transition: all 500ms ease;
            -moz-transition: all 500ms ease;
            transition: all 500ms ease;
        }

        .hsIco .hsIcoHolder {
            line-height: inherit;
            display: inline-block;
            float: left;
        }

        .hsIco .hsIcoHolder:before {
            /*            color: black;*/
            border-radius: 50%;
            display: inline-block;
            float: left;
            text-align: center;
            vertical-align: middle;
            -webkit-transition: all .3s ease;
            -moz-transition: all .3s ease;
            transition: all .3s ease;
        }

        .hsIco .hsIcoHolder[data-ico-fa]:before {
            font-family: FontAwesome;
            content: attr(data-ico-fa);
        }

        /* End Social Icon Styling */

        /* Couple Name Styling */
        .hs_venueevent {
            font-size: 90px;
            margin: 10px;
            text-align: center;
            font-family: 'Playfair Display', serif;
            font-weight: 500;
            white-space: nowrap;
        }

        .hs_venueeventcont {
            max-width: 635px;
            margin: 0 auto;
        }

        /* End Couple Name Styling */

        /* Couple Photo Styling */
        .hs_venuelogo {
            display: flex;
            justify-content: center;
            align-items: center;
            width: 300px;
            overflow: hidden;
            margin: 0 auto;
        }

        .hs_venuelogo img {
            width: 100%;
            height: 100%;
            object-fit: fill;
        }

        /* End Couple Photo Styling */

        /* Countdown Clock Styling */
        .hs_CounterHolder {
            font-size: 65px;
            line-height: 1;
            font-weight: 300;
            width: 100%;
            /*
            height: 200px;
            padding-top: 20px;
*/
        }

        .hs_CounterHolder .hs_Counter {
            display: block;
            height: 1em;
            overflow: hidden;
        }

        .hs_CounterHolder span.onedigit {
            display: inline-block;
            height: 1em;
            line-height: 1;
            -webkit-transition: all 1s ease 0s;
            -moz-transition: all 1s ease 0s;
            transition: all 1s ease 0s;
        }

        .hs_CounterHolder span.onedigit span {
            display: block;
            position: relative;
            height: 1em;
            text-align: center;
        }

        .hs_CounterHolder .hs_CountdownHolder {
            /*            width: 900px;*/
            margin: 0 auto;
            text-align: center;
        }

        .hs_CounterHolder .hs_CountdownHolder span.hs_countdown_numbers>span {
            vertical-align: top;
            display: inline-block;
            padding: 0 0 5px;
        }

        .hs_CounterHolder .hs_CountdownHolder div.hs_countdown_numbers {
            padding-top: 20px;
            line-height: 0;
            text-align: center;
            font-family: 'Montserrat', sans-serif;
            font-weight: 300;
            /*            color: black;*/
        }

        .hs_CounterHolder .hs_CountdownHolder div.hs_countdown_numbers>span {
            display: inline-block;
            overflow: hidden;
            height: 1em;
            line-height: 1;
        }

        .hs_CounterHolder .hs_CountdownHolder div.hs_countdown_numbers>span>span {
            display: block;
            width: 1em;
            text-align: center;
            -webkit-transition: transform 0ms ease-out;
            -moz-transition: transform 0ms ease-out;
            transition: transform 0ms ease-out;
            -webkit-transform: translateY(-100%);
            -moz-transform: translateY(-100%);
            -ms-transform: translateY(-100%);
            transform: translateY(-100%);
            position: static;
            height: 1em;
            line-height: 1;
            font-weight: 700;
        }

        .hs_CounterHolder .hs_CountdownHolder .hs_days div.hs_countdown_numbers>span>span {
            -webkit-transform: translateY(0%);
            -moz-transform: translateY(0%);
            -ms-transform: translateY(0%);
            transform: translateY(0%);
        }

        .hs_CounterHolder .hs_CountdownHolder .hs_days,
        .hs_CounterHolder .hs_CountdownHolder .hs_hours,
        .hs_CounterHolder .hs_CountdownHolder .hs_minutes,
        .hs_CounterHolder .hs_CountdownHolder .hs_seconds {
            margin: 5px;
            width: 23%;
            display: inline-block;
            /*            border: 2px solid black;*/
        }

        .hs_CounterHolder .hs_CountdownHolder div[class$="_text"] {
            position: relative;
            display: block;
            text-align: center;
            font-size: 32px;
            text-transform: capitalize;
            /*            color: black;*/
            padding-bottom: 20px;
        }

        .hs_CounterHolder .hs_CountdownHolder .hs_Seperator {
            content: " ";
            height: 1px;
            width: 66px;
            display: block;
            position: absolute;
            left: 50%;
            top: 0;
            right: 0;
            bottom: 0;
            margin: 0 0 0 -33px;
            background-color: #000000;
            display: none;
        }

        .hs_CounterHolder .hs_CountdownHolder div[class$="_text"]>span {
            height: auto !important;
            -webkit-transform: none !important;
            -moz-transform: none !important;
            -ms-transform: none !important;
            transform: none !important;
            font-size: 22px;
            line-height: 1.2 !important;
        }

        .hs_CounterHolder .hs_CountdownHolder span.hs_separator {
            display: none;
        }

        .hs_CounterHolder .hs_CountdownHolder .hs_days_text span,
        .hs_CounterHolder .hs_CountdownHolder .hs_hours_text span,
        .hs_CounterHolder .hs_CountdownHolder .hs_minutes_text span,
        .hs_CounterHolder .hs_CountdownHolder .hs_seconds_text span {
            width: auto !important;
            -webkit-transform: translate(-50%, -0.1em) !important;
            -moz-transform: translate(-50%, -0.1em) !important;
            -ms-transform: translate(-50%, -0.1em) !important;
            transform: translate(-50%, -0.1em) !important;
        }

        .hs_CounterHolder .hs_CountdownHolder div.hs_countdown_numbers>span>span.countdown_anim {
            -webkit-transition: transform 200ms ease-out;
            -moz-transition: transform 200ms ease-out;
            transition: transform 200ms ease-out;
            -webkit-transform: translateY(0);
            -moz-transform: translateY(0);
            -ms-transform: translateY(0);
            transform: translateY(0);
        }

        .hs_CounterHolder {
            /* Numbers Font*/
            font-family: 'Montserrat', sans-serif;
        }

        /* End Countdown Clock Styling */

    </style>

    <style>
        @media (max-width: 1200px) {

            .hs_presentedby {
                width: 300px;
                height: 128px;
                bottom: 50px;
                left: 5px;
            }

            .hs_presentedby_link {
                display: inline-block;
                width: 300px;
                /*                height: 128px;*/
            }
        }

        /*        mobile styling*/
        @media (max-width: 920px) {

            /*Counter Styling Mobile*/
            .hs_CounterHolder {
                height: 10px;
            }

            .hs_CounterHolder .hs_CountdownHolder {
                width: 100%;
            }

            .hs_CounterHolder .hs_CountdownHolder .hs_days,
            .hs_CounterHolder .hs_CountdownHolder .hs_hours,
            .hs_CounterHolder .hs_CountdownHolder .hs_minutes,
            .hs_CounterHolder .hs_CountdownHolder .hs_seconds {
                width: 20%;
                margin-bottom: .5em;
            }

            .hs_CounterHolder .hs_CountdownHolder div.hs_countdown_numbers>span>span {
                display: block;
                text-align: center;
                -webkit-transition: transform 0ms ease-out;
                -moz-transition: transform 0ms ease-out;
                transition: transform 0ms ease-out;
                -webkit-transform: translateY(-100%);
                -moz-transform: translateY(-100%);
                -ms-transform: translateY(-100%);
                transform: translateY(-100%);
                position: static;
                height: 1em;
                line-height: 1;
                font-size: 23px;
            }

            .hs_CounterHolder .hs_CountdownHolder div.hs_countdown_numbers>span {
                display: inline-block;
                overflow: hidden;
                height: .3em;
                line-height: 1;
            }

            .hs_CounterHolder .hs_CountdownHolder div[class$="_text"]>span {
                font-size: 17px;
            }

            .hs_CounterHolder .hs_CountdownHolder div.hs_countdown_numbers {
                padding-top: 15px;
            }

            .hs_CounterHolder .hs_CountdownHolder div[class$="_text"] {
                padding-bottom: 15px;
            }

            /*End Counter Styling Mobile*/
        }

        @media (max-height: 600px) {
            .hs_presentedby {
                display: none;
            }
        }


        @media (max-width: 635px) {

            .hs_venuelogo {
                width: 250px;
            }

            .hs_presentedby {
                margin: 0 auto;
                position: revert;
                width: 230px;
                height: 98px;
            }

            .hs_presentedby_link {
                display: inline-block;
                width: 100%;
                margin: 0 auto;
                height: revert;
            }

            /* Player Styling Mobile */
            .hs_playercontainer {
                width: 90%;
                margin: 0 auto;
                padding-top: 30px;
            }

            .hs_playerbox {
                width: 100%;
                margin: 0 auto;
            }

            /* End Player Styling Mobile */



            /*Time and Date Styling Mobile*/
            .hs_dateandtime {
                font-size: 22px;
            }

            /*End Time and Date Styling Mobile*/

            /*Venue Styling Mobile*/
            .hs_venue {
                font-size: 22px;
            }

            /*End Venue Styling Mobile*/

            /* Social Icon Styling Mobile*/
            .hs_socials {
                padding: 0px;
            }

            .hsIco .hsIcoHolder {
                font-size: 40px;
            }

            /* End Social Icon Styling Mobile*/

            /* Couple Photo Styling Mobile */
            /*
            .hs_coupleimage {
                max-width: 90%;
            }
*/

            /* End Couple Photo Styling Mobile */

            /* Couple Name Styling Mobile */
            .hs_venueevent {
                font-size: 40px;
                margin: 0px;
            }

            .hs_venueeventcont {
                max-width: 90%;
            }

            /* End Couple Name Styling Mobile */

            /* Before Text Styling Mobile */
            .hs_beforetext {
                font-size: 40px;
                margin: 0px;
            }

            .hs_beforetextcont {
                max-width: 90%;
            }

            /* End Before Text Styling Mobile */

            /* After Text Styling Mobile */
            .hs_aftertext {
                font-size: 40px;
                margin: 0px;
            }

            .hs_aftertextcont {
                max-width: 90%;
            }

            /* End After Text Styling Mobile */

        }

    </style>
</head>

<body class="hs_body" id="hs_page_body">
    <?php
    date_default_timezone_set("America/Denver");
    // Hook to include default WordPress hook after body tag open
    if (function_exists("wp_body_open")) {
        wp_body_open();
    }
    ?>
    <?php if (have_posts()) {
        while (have_posts()):
            the_post();
            $HSContent = hs_get_all();
            $items = explode(",,", $HSContent);
            foreach ($items as $item) {
                if (!empty($item)) {
                    list($variable, $value) = explode("=>", $item);
                    $$variable = $value;
                    //List each available variable. Uncomment when needed.
//                    echo $variable . "=" . $value . "<br>";
                }
            }
    
// HARD CODE PAGE VARIABLES HERE IF PAGE IS NOT DYNAMIC
//$SocialUrl1 = 'https://facebook.com';
//$SocialIcon1 = 'fa_f082';
//$SocialUrl2 = 'instagram.com';
//$SocialIcon2 = 'fa_f16d';
//$Couplename1 = 'Chad';
//$Couplename2 = 'Cheryl';
//$CouplenameSep = '&';
//$PlayerID = 'https://player.castr.com/live_c6fd66a08c6311ed8b70853442dccc1f';
//$Beforetext = 'Please join us here for our special day!';
//$Aftertext = 'We are gathered here RIGHT NOW!';
//$Color1 = '#f90085';
//$Color2 = '#2512dd';
//$Color3 = '#dbdd12';
//$DateTime = '3/29/2024 02:08:00';
//$CoupleImageUrl = 'https://hitchstream.com/wp-content/uploads/2023/01/chadcheryl3.jpg';
//$pagetimezone = 'pacific';
//$Venuename = 'Cranberry Lake Farm';
//$VenueUrl = 'https://cranberrylakefarm.com/';

// END HARD CODED VARIABLES
    
// BEGIN WRITING PAGE HTML
    
        //    Hex to RGBA Converter for color opacity
    function hexToRGBA($hex, $opacity) {
        $hex = str_replace('#', '', $hex);
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        return "rgba($r, $g, $b, $opacity)";
    }
    $Color1WithOpacity = hexToRGBA($Color1, 0.4);
    $Color2WithOpacity = hexToRGBA($Color2, 0.5);
    $Color3WithOpacity = hexToRGBA($Color3, 0.5);
    
            /* Write background image div */
    
            echo '<div class="hs_container" style="background-image: url(\'https://hitchstream.com/wp-content/themes/celebration-child/img/lights_overlay4.jpg\'), url(\'' . $VenuePhotoImageUrl . '\');"><div class="hs_blur"><div class="hs_width" style="background-color:' . $Color1WithOpacity . '; box-shadow: 4px 6px 13px 3px rgba(0, 0, 0, 0.3); border: 1px solid ' .  $Color3WithOpacity . ';">';
            /* End Write background image div */
    
            /* This is a hidden div that knows if the event is over or not */
            $IsComplete = get_status();
            echo '<div id="EventStatus" class="hideme">' . $IsComplete . '</div>';
    
                /* Venue Logo */
            $venuelogooutput =
                '<div id="hs_venuelogo" class="hs_venuelogo"><img src="' .
                $VenueLogoImageUrl .
                '" id="hs_MainImage" alt="' .
                $Venuename .
                '"></div>';
            echo $venuelogooutput;
            /* VenueLogo */
    
            /* Venue Event Title */
                $venueeventtitle =
                    '<div class="hs_venueeventcont"><div id="hs_venueevent" class="hs_venueevent" style="color:' . $Color3 . ';">' .
                    $Venueevent . '</div></div>';
            
            echo $venueeventtitle;
            /* End Venue Event Title */

            /* Before Text */
            $beforetextoutput =
                '<div id="hs_beforetextcont" class="hs_beforetextcont"><div id="hs_beforetext" class="hs_beforetext" style="color:' . $Color2 . ';">' .
                $Beforetext .
                "</div></div>";
            echo $beforetextoutput;
            /* Before Text */

            /* After Text */
            $aftertextoutput =
                '<div id="hs_aftertextcont" class="hs_aftertextcont"><div id="hs_aftertext" class="hs_aftertext" style="color:' . $Color2 . ';">' .
                $Aftertext .
                "</div></div>";
            echo $aftertextoutput;
            /* After Text */

            /* Video Player */
            $playeroutput =
                '<div id="hs_player" class="hs_playercontainer"><div class="hs_playerbox"> <iframe src="' . $PlayerURL . '" style="border: none; width: 100%; aspect-ratio: 16 / 9;" allow="fullscreen; accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe> </div></div>';
            echo $playeroutput;
            /* End Video Player */

            /* Countdown Clock */
            $DateTime = sanitize_text_field($DateTime);

            $target = strtotime($DateTime);
            $now = strtotime("now");

            $init_seconds = $target - $now;
            if ($init_seconds < 0) {
                $init_seconds = 0;
            }

            $d_text = __("Days", "bt_plugin");
            $h_text = __("Hours", "bt_plugin");
            $m_text = __("Minutes", "bt_plugin");
            $s_text = __("Seconds", "bt_plugin");

            $countdownoutput = '<div id="hs_counter" class="hs_CounterHolder" style="color:' . $Color3 . ';">';
            $countdownoutput .=
                '<div class="hs_CountdownHolder" data-init-seconds="' .
                $init_seconds .
                '">';

            $countdownoutput .=
                '<div class="hs_days" data-text="' . $d_text . '"></div>';

            $countdownoutput .=
                '<div class="hs_hours"><div class="hs_countdown_numbers"><span class="n0"><span></span><span></span></span><span class="n1"><span></span><span></span></span></div><div class="hs_hours_text"><div class="hs_Seperator"></div><span>' .
                $h_text .
                "</span></div></div>";

            $countdownoutput .=
                '<div class="hs_minutes"><div class="hs_countdown_numbers"><span class="n0"><span></span><span></span></span><span class="n1"><span></span><span></span></span></div><div class="hs_minutes_text"><div class="hs_Seperator"></div><span>' .
                $m_text .
                "</span></div></div>";

            $countdownoutput .=
                '<div class="hs_seconds"><div class="hs_countdown_numbers"><span class="n0"><span></span><span></span></span><span class="n1"><span></span><span><span></span></div><div class="hs_seconds_text"><div class="hs_Seperator"></div><span>' .
                $s_text .
                "</span></div></div>";

            $countdownoutput .= "</div>";
            $countdownoutput .= "</div>";

            echo $countdownoutput;
            /* End Countdown Clock */

            /* Date and Time */
            $dateObj = new DateTime(
                $DateTime,
                new DateTimeZone("America/Denver")
            );
            if ($pagetimezone == "pacific") {
                $dateObj->setTimezone(new DateTimeZone("America/Los_Angeles"));
            }
            if ($pagetimezone == "mountain") {
                $dateObj->setTimezone(new DateTimeZone("America/Denver"));
            }
            if ($pagetimezone == "central") {
                $dateObj->setTimezone(new DateTimeZone("America/Chicago"));
            }
            if ($pagetimezone == "eastern") {
                $dateObj->setTimezone(new DateTimeZone("America/New_York"));
            }

            $timezoneAbbreviations = [
                "pacific" => "PT",
                "mountain" => "MT",
                "central" => "CT",
                "eastern" => "ET"
            ];
            $abbreviation = $timezoneAbbreviations[$pagetimezone];

            $FormatDateTime = $dateObj->format("l F jS, Y | g:i A") . " " . $abbreviation;
            $datetimeoutput =
                '<div class="hs_dateandtimecont"><div class="hs_dateandtime" style="color:' . $Color2 . ';">' . $FormatDateTime . "</div></div>";
            echo $datetimeoutput;


            /* End Date and Time */
    
            /* Venue */
            $venueoutput = '<div class="hs_venue" style="color:' . $Color3 . ';"><a href = "' . $VenueUrl . '" target="_blank"> At ' . $Venuename . '</a></div>';
            echo $venueoutput;
            /* End Venue */

            /* Social Icons */
            $icon1html = hs_get_icon_html(
                $SocialIcon1,
                $SocialUrl1,
                "hs_Ico",
                "_blank",
                ""
            );
            $icon2html = hs_get_icon_html(
                $SocialIcon2,
                $SocialUrl2,
                "hs_Ico",
                "_blank",
                ""
            );
            $icon3html = hs_get_icon_html(
                $SocialIcon3,
                $SocialUrl3,
                "hs_Ico",
                "_blank",
                ""
            );

            $socialiconsoutput = '<div id="hs_socials" class="hs_socials" style="color:' . $Color3 . ';">';

            if ($SocialIcon1 != "") {
                $socialiconsoutput .= $icon1html;
            }

            if ($SocialIcon2 != "") {
                $socialiconsoutput .= $icon2html;
            }

            if ($SocialIcon3 != "") {
                $socialiconsoutput .= $icon3html;
            }

            $socialiconsoutput .= "</div>";
            echo $socialiconsoutput;
            /* End Social Icons */

            /* Colors */
            $colorsoutput =
                '<div class="hideme"><div id="color1">' .
                $Color1 .
                '</div><div id="color2">' .
                $Color2 .
                '</div><div id="color3">' .
                $Color3 .
                "</div></div>";
            echo $colorsoutput;
            /* End Colors */
    
            $presentedby = '<a href="https://hitchstream.com" target="_blank" rel="noopener noreferrer" class="hs_presentedby_link"><div class="hs_presentedby"></div></a>';
            echo $presentedby;
            echo "</div></div></div>";
    
// END WRITING PAGE HTML
        endwhile;
    } ?>

    <?php wp_footer(); ?>

    <script>
        window.addEventListener('DOMContentLoaded', function() {
            var eventStatus = document.getElementById('EventStatus');
            if (eventStatus.textContent === 'complete') {
                document.getElementById('hs_aftertext').textContent = "Thanks for Joining!!!";
            }
        });
        // Load textscaler.js
        jQuery.getScript("<?php echo get_stylesheet_directory_uri() . '/js/textscaler.js'; ?>")
            .done(function() {
                // This script watches a hidden div to see if the event is over and switches to the we're married text.
                var ajaxurl = "<?php echo admin_url('admin-ajax.php'); ?>";
                const targetNode = document.getElementById("EventStatus");
                const config = {
                    childList: true,
                    subtree: true,
                    characterData: true
                };
                const callback = (mutationsList) => {
                    for (const mutation of mutationsList) {
                        if (mutation.type === "characterData" || mutation.type === "childList") {
                            if (targetNode.textContent.trim() === "complete") {
                                document.getElementById("hs_player").style.display = "none";
                                document.getElementById("hs_aftertext").textContent = "Thanks for Joining!!!";
                                textScale('.hs_aftertextcont', '.hs_aftertext', 40);
                            }
                        }
                    }
                };
                const observer = new MutationObserver(callback);
                observer.observe(targetNode, config);

                // Autoscales text to fit within div on one single line. 
                function applyTextScaling() {
                    textScale('.hs_venueeventcont', '.hs_venueevent', 90);
                    textScale('.hs_aftertextcont', '.hs_aftertext', 40);
                    textScale('.hs_beforetextcont', '.hs_beforetext', 40);
                    textScale('.hs_dateandtimecont', '.hs_dateandtime', 40);
                }
                // Trigger text scaling on document ready
                jQuery(document).ready(applyTextScaling);
                // Trigger text scaling on window resize
                jQuery(window).on('resize', applyTextScaling);
            })
            .fail(function() {
                console.error("Error: textscaler.js could not be loaded.");
            });

    </script>

</body>

</html>
