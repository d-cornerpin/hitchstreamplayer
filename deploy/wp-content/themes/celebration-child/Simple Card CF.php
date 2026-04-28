<?php
/*
Template Name: Player - Simple Card
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
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400..900&family=Roboto:ital,wght@0,100;0,300;0,400;0,500;0,700;0,900;1,100;1,300;1,400;1,500;1,700;1,900&family=Tangerine:wght@400;700&display=swap" rel="stylesheet">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
    <script>
        var ajaxurl = "<?php echo admin_url("admin-ajax.php"); ?>";

    </script>

    <!--    Page variables-->
    <?php
    $resizes = "hs_beforetext,30,hs_aftertext,30,hs_couplename,30,hs_footer,28";
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


        /* Background Image and Effects*/
        .background-image {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: no-repeat center center;
            background-size: cover;
            z-index: -2;
        }

        .effect-overlay {
            backdrop-filter: brightness(80%) saturate(0%) contrast(120%) blur(4px);
            -webkit-backdrop-filter: brightness(80%) saturate(0%) contrast(120%) blur(4px);
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: -1;
        }

        .shimmer {
            color: grey;
            display: inline-block;
            -webkit-mask: linear-gradient(-60deg, #000 30%, #0005, #000 70%) right/300% 100%;
            background-repeat: no-repeat;
            animation: shimmer 19.5s infinite;
        }

        @keyframes shimmer {

            0%,
            100% {
                -webkit-mask-position: right;
            }

            50% {
                -webkit-mask-position: left;
            }
        }

        .content {
            text-align: center;
            position: fixed;
            top: 0;
            left: 0;
            /* width: 60%; */
            height: 95%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 1;
            margin: 0 auto;
            right: 0;
            background-color: rgba(235, 235, 235, 0.7);
            margin-top: 20px;
            margin-bottom: 20px;
            padding: 20px;
            aspect-ratio: 8/11.3;
            box-shadow: 0px 0px 12px 15px rgb(0 0 0 / 40%);
            overflow: hidden;
            border-radius: 4px;
        }

        /* Background Image and Effects*/

        .hs_eventtitle {
            font-family: "Tangerine", cursive;
            font-weight: 400;
            font-style: normal;
            font-size: 60px;
            margin-top: 15px;
        }

        /*Before Wedding Text Styling */
        .hs_beforetextcont {
            max-width: 635px;
            margin: 0 auto;
            width: 100%;
            padding-left: 20px;
            padding-right: 20px;
        }

        .hs_beforetext {
            font-family: "Roboto", sans-serif;
            font-weight: 300;
            font-style: normal;
            text-align: center;
            white-space: nowrap;
        }

        /*End Before Wedding Text Styling */

        /*After Wedding Starts Text Styling */
        .hs_aftertextcont {
            max-width: 635px;
            margin: 0 auto;
            width: 100%;
            padding-left: 20px;
            padding-right: 20px;
        }

        .hs_aftertext {
            font-family: "Roboto", sans-serif;
            font-weight: 300;
            font-style: normal;
            text-align: center;
            white-space: nowrap;
        }

        /*End After Wedding Starts Text Styling */

        /*Time and Date Styling*/
        .hs_dateandtime {
            text-align: center;
            font-family: "Roboto", sans-serif;
            font-weight: 300;
            font-style: normal;
            white-space: nowrap;
            /*            font-size: 22px;*/
        }

        /*End Time and Date Styling*/

        /*Venue Styling*/
        .hs_venue {
            text-align: center;
            font-family: "Roboto", sans-serif;
            font-weight: 300;
            font-style: normal;
            white-space: nowrap;
            /*            font-size: 30px;*/
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
            padding-left: 30px;
            padding-right: 30px;
            /*            height: 361px;*/
            /*            padding-top: 30px;*/
        }

        .hs_playerbox {
            width: 100%;
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
        .hs_couplename {
            /*            font-size: 24px;*/
            text-align: center;
            font-family: "Cinzel", serif;
            font-optical-sizing: auto;
            font-weight: 400;
            font-style: normal;
            white-space: nowrap;
        }

        .hs_couplenamecont {
            max-width: 635px;
            width: 100%;
            padding-left: 20px;
            padding-right: 20px;
        }

        /* End Couple Name Styling */

        .hs_footercont {
            max-width: 635px;
            width: 100%;
            padding-left: 60px;
            padding-right: 60px;
        }

        /* Couple Photo Styling */
        .hs_coupleimage {
            display: flex;
            justify-content: center;
            align-items: center;
            /*            aspect-ratio: 4/5;*/
            overflow: hidden;
            margin: 0 auto;
            /*            border-radius: 4px;*/
            margin-top: -8px;
            border: solid 4px white;
            box-shadow: 0px 0px 11px 3px rgb(0 0 0 / 40%);
            max-width: 90%;
        }

        .hs_coupleimage img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* End Couple Photo Styling */

        /* Countdown Clock Styling */
        .hs_CounterHolder {
            font-size: 25px;
            line-height: 1;
            font-weight: 300;
            width: 100%;
            height: 130px;
            padding-top: 10px;
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
            padding-left: 20px;
            padding-right: 20px;
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
            font-family: "Cinzel", serif;
            font-optical-sizing: auto;
            font-weight: 400;
            font-style: normal;
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
            font-size: 18px;
            text-transform: capitalize;
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
            font-family: "Cinzel", serif;
            font-optical-sizing: auto;
            font-weight: 400;
            font-style: normal;
        }

        /* End Countdown Clock Styling */

    </style>

    <style>
        /*        mobile styling*/
        @media (max-width: 920px) {

            .content {
                width: 95%;
                background-size: 100% 100%;
            }

            /*End Counter Styling Mobile*/
        }

        @media (max-width: 635px) {
            .hs_eventtitle {
                font-size: 40px;
            }

            .hs_coupleimage {
                margin-top: 0px;
                max-height: revert;
            }

            .hs_CounterHolder .hs_CountdownHolder div[class$="_text"]>span {
                font-size: 10px;
            }

            .hs_CounterHolder .hs_CountdownHolder div.hs_countdown_numbers {
                padding-top: 10px;
            }

            .hs_CounterHolder .hs_CountdownHolder div[class$="_text"] {
                font-size: 0px;
                padding-bottom: 10px;
            }

            .hs_CounterHolder .hs_CountdownHolder {
                padding-left: 5px;
                padding-right: 5px;
            }

            .hs_CounterHolder .hs_CountdownHolder .hs_days,
            .hs_CounterHolder .hs_CountdownHolder .hs_hours,
            .hs_CounterHolder .hs_CountdownHolder .hs_minutes,
            .hs_CounterHolder .hs_CountdownHolder .hs_seconds {
                width: 21%;
            }

            .hs_CounterHolder {
                height: 75px;
                font-size: 18px;
            }

            .content {
                height: revert;
            }

            .hs_playercontainer {
                padding-left: 20px;
                padding-right: 20px;
            }

            .hs_footercont {
                padding-top: 10px;
            }

            .hs_socials {
                padding-bottom: 0;
            }

            .hs_eventtitle {
                margin-top: 0;
            }

            .hs_coupleimage img {
                width: auto;
                height: auto;
                max-height: 150px;
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
                    //echo $variable . "=" . $value . "<br>";
                }
            }
            

            $svg_code = '<svg version="1.1" id="Layer_1" xmlns="http://www.w3.org/2000/svg" x="0" y="0" viewBox="0 0 595.28 841.89" preserveAspectRatio="none" style="enable-background:new 0 0 595.28 841.89" xml:space="preserve">
                <style>
                    .st0{fill:FILLCOLOR}
                </style>
                    <path class="st0" d="M549.28 834.77H45.72v-35.82H9.9V42.05h35.82V6.23h503.56v35.82h35.82v756.89h-35.82v35.83zM47.19 833.3h500.62v-34.36c-11.56-.21-17.77-5.27-19.73-10.01-1.43-3.46-.81-7.02 1.68-9.5 1.59-1.59 3.61-2.43 5.85-2.43 6.51 0 13.42 7.17 13.67 20.48h34.36V43.52h-34.36C549.03 56.83 542.12 64 535.61 64c-2.23 0-4.26-.84-5.85-2.43-2.49-2.49-3.11-6.04-1.68-9.5 1.96-4.74 8.17-9.81 19.73-10.01V7.7H47.19v34.36c11.56.21 17.77 5.27 19.73 10.01 1.43 3.47.81 7.02-1.68 9.5-1.59 1.59-3.61 2.43-5.85 2.43-3.41 0-6.8-1.97-9.3-5.4-1.93-2.65-4.22-7.39-4.36-15.09H11.37v753.95h34.36c.25-13.31 7.16-20.48 13.67-20.48 2.23 0 4.26.84 5.85 2.43 2.49 2.49 3.11 6.04 1.68 9.5-1.96 4.74-8.17 9.8-19.73 10.01v34.38zm488.42-54.84c-1.84 0-3.5.69-4.81 2-2.05 2.05-2.56 5-1.36 7.9 1.78 4.31 7.54 8.9 18.36 9.1-.23-12.34-6.39-19-12.19-19zm-476.22 0c-5.8 0-11.96 6.66-12.2 19.01 10.83-.2 16.58-4.8 18.36-9.1 1.2-2.9.69-5.85-1.36-7.9-1.3-1.31-2.96-2.01-4.8-2.01zM547.8 43.53c-10.83.2-16.58 4.8-18.36 9.1-1.2 2.9-.69 5.85 1.36 7.9 1.31 1.31 2.97 2 4.81 2 5.8.01 11.96-6.65 12.19-19zm-500.6 0c.14 7.29 2.28 11.74 4.08 14.21 2.23 3.05 5.18 4.79 8.12 4.79 1.84 0 3.5-.69 4.81-2 2.05-2.05 2.56-5 1.36-7.9-1.79-4.3-7.55-8.9-18.37-9.1z"/>
                    <path class="st0" d="M571.13 820.79H23.87V20.2h547.26v800.59zm-544.32-2.94h541.38V23.15H26.81v794.7z"/>
            </svg>';

            $svg_code = str_replace("FILLCOLOR", $Color1, $svg_code);
            $svg_code = rawurlencode($svg_code);
    
            echo "<div class='background-image' style='background-image: url(\"{$BackgroundImageUrl}\"); background-color: {$BackgroundColor};'></div><div class='effect-overlay shimmer'></div><div class='content' style='background-image: url(\"data:image/svg+xml;utf8," . $svg_code . "\");'>";



            /* This is a hidden div that knows if the event is over or not */
            $IsComplete = get_status();
            echo '<div id="EventStatus" class="hideme">' . $IsComplete . '</div>';

            
            $eventtitle = '<div id="hs_eventtitle" class="hs_eventtitle" style="color:' . $Color3 . ';">' . $Eventtitle . '</div>';
            echo $eventtitle;

            /* Couple Photo */
            $couplephotooutput = '<div id="hs_coupleimage" class="hs_coupleimage"><img src="' . $CoupleImageUrl . '" id="hs_MainImage" alt="' . $Couplename1 . " " . $CouplenameSep . " " . $Couplename2 . '"></div>';
            echo $couplephotooutput;
            /* End Couple Photo */
    
            /* Couple Name */
            if ($NameImageUrl != "") {
                $couplenameoutput = '<div id="hs_couplename" class="hs_couplename"><img src="' . esc_url_raw($NameImageUrl) . '" id="hs_couplenameimage" alt="' . $Couplename1 . " " . $CouplenameSep . " " . $Couplename2 . '"></div>';
            } else {
                $couplenameoutput =
                '<div class="hs_couplenamecont"><div id="hs_couplename" class="hs_couplename">' .
                '<span class="firstname" style="display: block; color:' . $Color2 . ';">' .
                $Couplename1 . '</span>' .
                '<span class="secondname" style="display: block; color:' . $Color2 . '; margin-top: -10px;">' . // Adjusted here
                $Couplename2 .
                '</span></div></div>';


            }
            echo $couplenameoutput;
            /* End Couple Name */
    
            /* Before Text */
            $beforetextoutput = '<div id="hs_beforetextcont" class="hs_beforetextcont"><div id="hs_beforetext" class="hs_beforetext" style="color:' . $Color3 . ';">' . $Beforetext . "</div></div>";
            echo $beforetextoutput;
            /* Before Text */

            /* After Text */
            $aftertextoutput = '<div id="hs_aftertextcont" class="hs_aftertextcont"><div id="hs_aftertext" class="hs_aftertext" style="color:' . $Color3 . ';">' . $Aftertext . "</div></div>";
            echo $aftertextoutput;
            /* After Text */

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

            $countdownoutput = '<div id="hs_counter" class="hs_CounterHolder">';
            $countdownoutput .= '<div class="hs_CountdownHolder" data-init-seconds="' . $init_seconds . '" style="color:white";>';

            $countdownoutput .= '<div class="hs_days" data-text="' . $d_text . '" style="border: 2px solid' . $Color3 . '; color: ' . $Color3 . '"></div>';

            $countdownoutput .=
                '<div class="hs_hours" style="border: 2px solid' . $Color3 . ';"><div class="hs_countdown_numbers" style="color:' . $Color3 . ';"><span class="n0"><span></span><span></span></span><span class="n1"><span></span><span></span></span></div><div class="hs_hours_text" style="color:' . $Color3 . ';"><div class="hs_Seperator" style="color:' . $Color3 . ';"></div><span>' .
                $h_text .
                "</span></div></div>";

            $countdownoutput .=
                '<div class="hs_minutes" style="border: 2px solid' . $Color3 . ';"><div class="hs_countdown_numbers" style="color:' . $Color3 . ';"><span class="n0"><span></span><span></span></span><span class="n1"><span></span><span></span></span></div><div class="hs_minutes_text" style="color:' . $Color3 . ';"><div class="hs_Seperator" style="color:' . $Color3 . ';"></div><span>' .
                $m_text .
                "</span></div></div>";

            $countdownoutput .=
                '<div class="hs_seconds" style="border: 2px solid' . $Color3 . ';"><div class="hs_countdown_numbers" style="color:' . $Color3 . ';"><span class="n0"><span></span><span></span></span><span class="n1"><span></span><span><span></span></div><div class="hs_seconds_text" style="color:' . $Color3 . ';"><div class="hs_Seperator" style="color:' . $Color3 . ';"></div><span>' .
                $s_text .
                "</span></div></div>";

            $countdownoutput .= "</div>";
            $countdownoutput .= "</div>";

            echo $countdownoutput;
            /* End Countdown Clock */

            /* Date and Time */
            $dateObj = new DateTime($DateTime, new DateTimeZone("America/Denver"));
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
                "eastern" => "ET",
            ];
            $abbreviation = $timezoneAbbreviations[$pagetimezone];

            $FormatDateTime = $dateObj->format("l, F jS, Y | g:i A") . " " . $abbreviation;
            $datetimeoutput = '<div id="hs_footercont" class="hs_footercont"><div id="hs_footer" class="hs_footer"><div class="hs_dateandtime" style="color:' . $Color3 . ';">' . $FormatDateTime . "</div>";
            echo $datetimeoutput;

            /* End Date and Time */

            /* Venue */
            if (!empty($Venuename)) {
                $venueoutput = '<div class="hs_venue" style="color:' . $Color3 . ';">' . '<a href = "' . $VenueUrl . '">at ' . $Venuename . '</a></div></div></div>';
                echo $venueoutput;
            }

            /* End Venue */

            /* Social Icons */
            $icon1html = hs_get_icon_html($SocialIcon1, $SocialUrl1, "hs_Ico", "_blank", "");
            $icon2html = hs_get_icon_html($SocialIcon2, $SocialUrl2, "hs_Ico", "_blank", "");
            $icon3html = hs_get_icon_html($SocialIcon3, $SocialUrl3, "hs_Ico", "_blank", "");

            $socialiconsoutput = '<div id="hs_socials" class="hs_socials" style="color:' . $Color3 . ' !important;">';

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
            $colorsoutput = '<div class="hideme"><div id="color1">' . $Color1 . '</div><div id="color2">' . $Color2 . '</div><div id="color3">' . $Color3 . "</div></div>";
            echo $colorsoutput;
            /* End Colors */
            echo '</div>';
            // END WRITING PAGE HTML
        endwhile;
    } ?>

    <?php wp_footer(); ?>


</body>

</html>
