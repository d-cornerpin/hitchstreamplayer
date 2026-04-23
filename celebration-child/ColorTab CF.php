<?php
/*
Template Name: Player - Color Tab 
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
    <link href="https://fonts.googleapis.com/css2?family=Gilda+Display&family=Josefin+Sans:wght@300;400&display=swap" rel="stylesheet">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
    <script>
        var ajaxurl = "<?php echo admin_url("admin-ajax.php"); ?>";

    </script>


    <!--    Page variables-->
    <?php
	$resizes = "hs_beforetext,26,hs_aftertext,26,hs_couplename,70,hs_footer,40";
    	$endedText = "Check back for a recording of this event!";

    	// Convert $resizes into an array and prepare text scaling configuration
    	$resizeArray = explode(",", $resizes);
    	$textScalingConfig = [];
    	for ($i = 0; $i < count($resizeArray); $i += 2) {
        $textScalingConfig[] = [
            "parentClass" => ".{$resizeArray[$i]}cont",
            "childClass" => ".{$resizeArray[$i]}",
            "maxFontSize" => (int) $resizeArray[$i + 1],
        ];
    	}
    	$jsonTextScalingConfig = json_encode($textScalingConfig);
    	$jsonEndedText = json_encode($endedText);
?>

    <!-- Auto-text Scaling -->
    <script>
        var textScalingConfig = <?php echo $jsonTextScalingConfig; ?>;

    </script>
    <script src="/wp-content/themes/celebration-child/js/textscaler.js"></script>

    <!-- Ender Script -->
    <script>
        var endedMessage = <?php echo $jsonEndedText; ?>;

    </script>
    <script src="/wp-content/themes/celebration-child/js/ended.js"></script>



    <style>
        /* Hidden Element Class*/
        .hideme {
            display: none;
        }

        /* Hidden Element Class*/
        #hs_player {
            display: none;
        }

        /* NEW STRUCTURE*/
        body {
            background-color: white;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
        }


        .hs_background::before {
            background-size: cover;
            content: "";
            background-image: inherit;
            width: 100%;
            height: 100%;
            position: absolute;
            top: 0;
            left: 0;
            filter: blur(5px) opacity(20%) grayscale(100%);
            z-index: -1;
        }

        .hs_page {
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100vh;
        }

        .hs_container {
            background-color: rgba(255, 255, 255, 0.5);
            border-radius: 30px;
            box-shadow: 0px 4px 20px 15px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: row;
            width: 1366px;
            /*            height: 738px;*/
            position: relative;
            padding: 50px 0 50px 0;
        }

        .hs_corner {
            position: absolute;
            top: 50px;
            left: 50px;
            z-index: 9999;
        }

        .hs_weddinginfocont {
            width: 60%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding-left: 75px;
            height: 100%;
        }

        .hs_couplephoto {
            width: 40%;
            display: flex;
            align-items: center;
            /*            justify-content: center;*/
            position: relative;
            padding-left: 25px;
        }

        .hs_banner {
            margin: 0 auto;
        }

        /*END NEW STRUCTURE */

        /*Before Wedding Text Styling */
        .hs_beforetextcont {
            /*            max-width: 635px;*/
            margin: 0 auto;
        }

        .hs_beforetext {
            text-align: center;
            font-size: 30px;
            font-family: 'Josefin Sans', sans-serif;
            font-weight: 300;
            white-space: nowrap;
            text-transform: uppercase;
        }

        /*End Before Wedding Text Styling */

        /*After Wedding Starts Text Styling */
        .hs_aftertextcont {
            /*            max-width: 635px;*/
            margin: 0 auto;
        }

        .hs_aftertext {
            text-align: center;
            font-size: 30px;
            font-family: 'Josefin Sans', sans-serif;
            font-weight: 300;
            white-space: nowrap;
            text-transform: uppercase;
        }

        /*End After Wedding Starts Text Styling */

        /*Time and Date Styling*/
        .hs_dateandtime {
            text-align: center;
            padding-top: 30px;
            font-family: 'Josefin Sans', sans-serif;
            font-weight: 400;
            font-size: 25px;
        }

        /*End Time and Date Styling*/

        /*Venue Styling*/
        .hs_venue {
            text-align: center;
            font-family: 'Josefin Sans', sans-serif;
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
            /*            padding-bottom: 30px;*/
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
        .hs_couplename {
            font-size: 90px;
            margin: 10px;
            text-align: center;
            font-family: 'Gilda Display', serif;
            font-weight: 500;
            white-space: nowrap;
        }

        .hs_couplenamecont {
            max-width: 535px;
            margin: 0 auto;
            height: 130px;
        }

        /* End Couple Name Styling */

        /* Couple Photo Styling */
        .hs_coupleimage {
            display: flex;
            justify-content: center;
            align-items: center;
            width: 400px;
            height: 610px;
            overflow: hidden;
            border-radius: 50%/35%;
            border: 20px solid white;
            box-sizing: border-box;
            box-shadow: 0px 0px 15px 0px rgba(0, 0, 0, 0.1);
            position: relative;
            z-index: 2;
        }

        .hs_coupleimage img {
            width: auto;
            height: 100%;
            object-fit: cover;
        }

        .hs_flower {
            position: absolute;
            z-index: 1;
            right: 30px;
            bottom: -7px;
        }

        .hs_flower img {
            /* Add any necessary styles for the flower image */
        }

        /* End Couple Photo Styling */

        /* Countdown Clock Styling */
        .hs_CounterHolder {
            font-size: 40px;
            line-height: 1;
            font-weight: 300;
            width: 100%;
            /*            height: 200px;*/
            padding-top: 20px;
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
            width: 550px;
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
            line-height: 1;
            text-align: center;
            font-family: 'Gilda Display', serif;
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
            font-weight: 300;
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
            font-size: 25px;
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
            font-family: 'Gilda Display', serif;
        }

        /* End Countdown Clock Styling */

    </style>

    <style>
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

        @media (max-width: 635px) {

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
            .hs_coupleimage {
                max-width: 90%;
            }

            /* End Couple Photo Styling Mobile */

            /* Couple Name Styling Mobile */
            .hs_couplename {
                font-size: 40px;
                margin: 0px;
            }

            .hs_couplenamecont {
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
    
    
    //    Hex to RGBA Converter for color opacity
    function hexToRGBA($hex, $opacity) {
        $hex = str_replace('#', '', $hex);
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        return "rgba($r, $g, $b, $opacity)";
    }
    $Color1WithOpacity = hexToRGBA($Color1, 0.2);
    
// BEGIN WRITING PAGE HTML
    echo '<div class="hs_background" style="background-image: url(\'' . $CoupleImageUrl . '\')"></div><div class="hs_page" style="background-color:' . $Color1WithOpacity . ';">';

            /* This is a hidden div that knows if the event is over or not */
            $IsComplete = get_status();
            echo '<div id="EventStatus" class="hideme">' . $IsComplete . '</div>';
    
////// NEW STRUCTURE
                /* Container */
    $svg_url = 'https://hitchstream.com/wp-content/themes/celebration-child/img/swirlie_corner.svg';
    $svg_content = file_get_contents($svg_url);
    $modified_svg = str_replace('<svg ', '<svg style="width:190px;fill:' . $Color2 . ';" ', $svg_content);
    $container_output =
    '<div class="hs_container"><div class="hs_corner">' . $modified_svg . '</div>';
    echo $container_output;

    /* Wedding Info Container */
    $weddinginfocont_output =
        '<div class="hs_weddinginfocont">';
    echo $weddinginfocont_output;

    /* Banner */
    $svg_url = 'https://hitchstream.com/wp-content/themes/celebration-child/img/swirlie_hearts.svg';
    $svg_content = file_get_contents($svg_url);
    $modified_svg = str_replace('<svg ', '<svg style="width:400px;fill:' . $Color2 . ';" ', $svg_content);

    $banner_output = '
    <div class="hs_banner">
    ' . $modified_svg . '
    </div>';
    echo $banner_output;

    /* Couple Name Container */
    if ($NameImageUrl != "") {
                $couplenameoutput =
                    '<div id="hs_couplename" class="hs_couplename"><img src="' .
                    esc_url_raw($NameImageUrl) .
                    '" id="hs_couplenameimage" alt="' .
                    $Couplename1 .
                    " " .
                    $CouplenameSep .
                    " " .
                    $Couplename2 .
                    '"></div>';
            } else {
                $couplenameoutput =
                    '<div class="hs_couplenamecont"><div id="hs_couplename" class="hs_couplename" style="color:' . $Color2 . ';"><span class="firstname">' .
                    $Couplename1 .
                    " " .
                    $CouplenameSep .
                    " " .
                    '</span><span class="secondname">' .
                    $Couplename2 .
                    '</span><div class="hs_seperator2"></div></div></div>';
            }
            echo $couplenameoutput;
    
    /* Before and After Text */
            function wrapTextWithLineBreak($inputText) {
                $sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z])/', $inputText);
                return implode('<br>', $sentences);
            }

            $beforetextoutput = '<div id="hs_beforetextcont" class="hs_beforetextcont"><div id="hs_beforetext" class="hs_beforetext" style="color:' . $Color2 . ';">' . wrapTextWithLineBreak($Beforetext) . "</div></div>";
            $aftertextoutput = '<div id="hs_aftertextcont" class="hs_aftertextcont"><div id="hs_aftertext" class="hs_aftertext" style="color:' . $Color2 . ';">' . wrapTextWithLineBreak($Aftertext) . "</div></div>";

            echo $beforetextoutput;
            echo $aftertextoutput;
            /* End Before and After Text */



    /* Video Player */

// Output the player iframe
$playeroutput =
	'<div id="hs_player" class="hs_playercontainer" style="position: relative;">
		<div class="hs_playerbox" style="position: relative; width: desiredWidth; height: auto;">
			<iframe src="' . $PlayerURL . '" style="border: none; width: 100%; aspect-ratio: 16 / 9;" allow="fullscreen; accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>
		</div>
	</div>';

echo $playeroutput;

/* End Video Player */

    /* Counter Holder */
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

            $countdownoutput = '<div id="hs_counter" class="hs_CounterHolder">';
            $countdownoutput .=
                '<div class="hs_CountdownHolder" data-init-seconds="' .
                $init_seconds .
                '">';

            $countdownoutput .=
                '<div class="hs_days" data-text="' . $d_text . '" style="color:' . $Color2 . ';"></div>';

            $countdownoutput .=
                '<div class="hs_hours"><div class="hs_countdown_numbers" style="color:' . $Color2 . ';"><span class="n0"><span></span><span></span></span><span class="n1"><span></span><span></span></span></div><div class="hs_hours_text" style="color:' . $Color2 . ';"><div class="hs_Seperator"></div><span>' .
                $h_text .
                "</span></div></div>";

            $countdownoutput .=
                '<div class="hs_minutes"><div class="hs_countdown_numbers" style="color:' . $Color2 . ';"><span class="n0"><span></span><span></span></span><span class="n1"><span></span><span></span></span></div><div class="hs_minutes_text" style="color:' . $Color2 . ';"><div class="hs_Seperator"></div><span>' .
                $m_text .
                "</span></div></div>";

            $countdownoutput .=
                '<div class="hs_seconds"><div class="hs_countdown_numbers" style="color:' . $Color2 . ';"><span class="n0"><span></span><span></span></span><span class="n1"><span></span><span><span></span></div><div class="hs_seconds_text" style="color:' . $Color2 . ';"><div class="hs_Seperator"></div><span>' .
                $s_text .
                "</span></div></div>";

            $countdownoutput .= "</div>";
            $countdownoutput .= "</div>";

            echo $countdownoutput;

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
                '<div class="hs_dateandtime" style="color:' . $Color2 . ';">' . $FormatDateTime . "</div>";
            echo $datetimeoutput;

    /* Venue */
    $venueoutput = '<div class="hs_venue" style="color:' . $Color2 . ';"><a href = "' . $VenueUrl . '"> At ' . $Venuename . '</a></div>';
            echo $venueoutput;

    /* Socials */
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

            $socialiconsoutput = '<div id="hs_socials" class="hs_socials" style="color:' . $Color2 . ';">';

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

    /* End Wedding Info Container */
    echo '</div>';
      

    /* Couple Photo Container */
    $svg_url = 'https://hitchstream.com/wp-content/themes/celebration-child/img/swirlie_flower.svg';
    $svg_content = file_get_contents($svg_url);
    $modified_svg = str_replace('<svg ', '<svg style="width:200px;fill:' . $Color2 . ';" ', $svg_content);
    $couplephoto_output =
    '<div class="hs_couplephoto"><div class="hs_flower">
    ' . $modified_svg . '
    </div>';
    echo $couplephoto_output;

    /* Couple Photo */
    $couplephotooutput =
                '<div id="hs_coupleimage" class="hs_coupleimage"><img src="' .
                $CoupleImageUrl .
                '" id="hs_MainImage" alt="' .
                $Couplename1 .
                " " .
                $CouplenameSep .
                " " .
                $Couplename2 .
                '"></div>';
            echo $couplephotooutput;
    
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

    /* End Couple Photo Container and Container */
    echo '</div></div></div></div>';
////// NEW STRUCTURE END


    
// END WRITING PAGE HTML
        endwhile;
    } ?>

    <?php wp_footer(); ?>


</body>

</html>
