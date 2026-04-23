<?php
/*
Template Name: Player - Chalk Board 
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
    <link href="https://fonts.googleapis.com/css2?family=Amatic+SC:wght@400;700&family=Love+Light&family=Pangolin&family=Comfortaa:wght@300&family=Patrick+Hand&family=Puppies+Play&family=Ruge+Boogie&display=swap" rel="stylesheet">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>

    <script>
        var ajaxurl = "<?php echo admin_url("admin-ajax.php"); ?>";

    </script>


    <!--    Page variables-->
    <?php
	$resizes = "hs_couplename,100";
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
        #hs_player {
            display: none;
        }

        .hs_banner_svg {
            width: 400px;
        }

        .hs_seperator_svg {
            width: 500px;
        }


        /* Polaroid*/
        .polaroid {
            background: #fff;
            padding: 1rem;
            box-shadow: 2px 2rem 3rem 12px rgba(0, 0, 0, 0.7);

        }

        .polaroid>img {
            max-width: 100%;
            height: auto;
        }

        .caption {
            text-align: center;
            /*            line-height: 2em;*/
            font-family: 'Amatic SC', cursive;
        }

        .item {
            width: 100%;
            display: inline-block;
            margin-top: 2rem;
            filter: grayscale(100%);
        }

        .item .polaroid:before {
            content: '';
            position: absolute;
            z-index: -1;
            transition: all 0.35s;
        }

        .item:nth-of-type(4n+1) {
            transform: scale(0.8, 0.8) rotate(5deg);
            transition: all 0.35s;
        }

        .item:nth-of-type(4n+1) .polaroid:before {
            transform: rotate(6deg);
            height: 20%;
            width: 47%;
            bottom: 30px;
            right: 12px;
            box-shadow: 0 2.1rem 2rem rgba(0, 0, 0, 0.4);
        }

        .item:nth-of-type(4n+2) {
            transform: scale(0.8, 0.8) rotate(-5deg);
            transition: all 0.35s;
        }

        .item:nth-of-type(4n+2) .polaroid:before {
            transform: rotate(-6deg);
            height: 20%;
            width: 47%;
            bottom: 30px;
            left: 12px;
            box-shadow: 0 2.1rem 2rem rgba(0, 0, 0, 0.4);
        }

        .item:nth-of-type(4n+4) {
            transform: scale(0.8, 0.8) rotate(3deg);
            transition: all 0.35s;
        }

        .item:nth-of-type(4n+4) .polaroid:before {
            transform: rotate(4deg);
            height: 20%;
            width: 47%;
            bottom: 30px;
            right: 12px;
            box-shadow: 0 2.1rem 2rem rgba(0, 0, 0, 0.3);
        }

        .item:nth-of-type(4n+3) {
            transform: scale(0.8, 0.8) rotate(-3deg);
            transition: all 0.35s;
        }

        .item:nth-of-type(4n+3) .polaroid:before {
            transform: rotate(-4deg);
            height: 20%;
            width: 47%;
            bottom: 30px;
            left: 12px;
            box-shadow: 0 2.1rem 2rem rgba(0, 0, 0, 0.3);
        }

        .item:hover {
            filter: none;
            transform: scale(1, 1) rotate(0deg) !important;
            transition: all 0.35s;
        }

        .item:hover .polaroid:before {
            content: '';
            position: absolute;
            z-index: -1;
            transform: rotate(0deg);
            height: 90%;
            width: 90%;
            bottom: 0%;
            right: 5%;
            box-shadow: 0 1rem 3rem rgba(0, 0, 0, 0.2);
            transition: all 0.35s;
            /* End Polaroid*/
        }

    </style>
    <style>
        /* Hidden Element Class*/
        .hideme {
            display: none;
        }

        /* Hidden Element Class*/

        /* NEW STRUCTURE*/
        body {
            background-color: black;
            background-size: cover;
            background-position: center center;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            background-image: url('https://hitchstream.com/wp-content/themes/celebration-child/img/ChalkBoard.jpg');
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
            display: flex;
            flex-direction: row;
            width: 1236px;
            height: 738px;
            position: relative;
            padding: 50px 0 50px 0;
        }


        .hs_weddinginfocont {
            width: 50%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding-left: 75px;
            height: 100%;
        }

        .hs_couplephoto {
            width: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            padding-left: 25px;
        }

        .hs_banner {
            margin: 0 auto;
        }

        .hs_heart {
            position: absolute;
            top: 143px;
            left: 593px;
            transform: rotate(15deg);
        }

        /*END NEW STRUCTURE */

        /*Before Wedding Text Styling */
        .hs_beforetextcont {}

        .hs_beforetext {
            font-size: 50px;
            display: inline-block;
        }

        /*End Before Wedding Text Styling */

        /*After Wedding Starts Text Styling */
        .hs_aftertextcont {}

        .hs_aftertext {
            font-size: 50px;
        }

        /*End After Wedding Starts Text Styling */

        /*Time and Date Styling*/
        .hs_dateandtime {
            text-align: center;
            padding-top: 30px;
            font-family: 'Comfortaa', cursive;
            font-weight: 400;
            font-size: 25px;
        }

        /*End Time and Date Styling*/

        /*Venue Styling*/
        .hs_venue {
            text-align: center;
            font-family: 'Comfortaa', cursive;
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
            width: 100%;
            /*height: 337px;*/
            margin: 0 auto;
        }

        /* End Player Styling */

        /* Social Icon Styling */

        .socialcont {
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .hs_birdleft,
        .hs_socials,
        .hs_birdright {
            flex-shrink: 0;
        }

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
            color: white;
            border-radius: 50%;
            display: inline-block;
            float: left;
            text-align: center;
            vertical-align: middle;
            -webkit-transition: all .3s ease;
            -moz-transition: all .3s ease;
            transition: all .3s ease;
            opacity: 0.8;
        }

        .hsIco .hsIcoHolder[data-ico-fa]:before {
            font-family: FontAwesome;
            content: attr(data-ico-fa);
        }

        /* End Social Icon Styling */

        /* Couple Name Styling */
        .hs_couplename {
            font-size: 90px;
            margin-top: 10px;
            text-align: center;
            font-family: 'Love Light', cursive;
            font-weight: 400;
            white-space: nowrap;
        }

        .hs_couplenamecont {
            width: 90%;
            margin: 0 auto;
            height: 130px;
            top: -33px;
            position: relative;
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

        /* End Couple Photo Styling */

        /* Countdown Clock Styling */
        .hs_CounterHolder {
            font-size: 40px;
            line-height: 1;
            font-weight: 300;
            width: 100%;
            height: 119px;
            /*            padding-top: 20px;*/
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
            font-family: 'Comfortaa', cursive;
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
            /*
            border: 2px solid white;
            border-radius: 10px;
*/
        }

        .hs_CounterHolder .hs_CountdownHolder div[class$="_text"] {
            position: relative;
            display: block;
            text-align: center;
            font-size: 16px;
            text-transform: uppercase;
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
            font-size: 16px;
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
            font-family: 'Comfortaa', cursive;
        }

        /* End Countdown Clock Styling */

    </style>

    <style>
        /*        mobile styling*/
        @media (max-width: 920px) {

            .hs_playercontainer {
                padding-top: revert;
                top: -40px;
                position: relative;
            }

            .item:hover {
                filter: none;
                transform: scale(1, 1) rotate(0deg) !important;
                transition: all 0.35s;
            }

            .hs_couplenamecont {
                height: revert;
            }

            .caption {
                display: none;
            }

            .hs_beforetext {
                font-size: 30px;
            }

            .hs_dateandtime {
                font-size: 16px;
                padding-top: revert;
                top: -40px;
                position: relative;
            }

            .hs_heart {
                top: revert;
                left: revert;
                bottom: 0;
                right: 0;
                display: flex;
                position: fixed;
                transform: rotate(-15deg);
                width: 85px;
                padding: 5px;
            }

            .hs_couplephoto {
                position: fixed;
                width: 85px;
                left: 0;
                /*                right: 0;*/
                bottom: 0;
                margin: auto;
                padding-left: revert;
            }

            .polaroid {
                padding: .5rem;
            }

            .hs_weddinginfocont {
                width: 100%;
                padding-left: revert;
            }

            .hs_container {
                width: 100%;
                height: revert;
            }

            .hs_banner_svg {
                width: 100%;
            }

            .hs_seperator_svg {
                width: 100%;
            }

            .hs_seperator {
                top: -40px;
                position: relative;
            }

            .hs_venue {
                font-size: 20px;
                top: -40px;
                position: relative;
            }

            .hs_couplename {
                margin-top: revert;
            }

            .socialcont {
                top: -40px;
                position: relative;
            }

            /*Counter Styling Mobile*/
            .hs_CounterHolder {
                height: revert;
                top: -40px;
                position: relative;
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
                height: .5em;
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

        @media (max-width: 635px) {}

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
    $Color1WithOpacity = hexToRGBA($Color1, 0.8);
    $Color2WithOpacity = hexToRGBA($Color2, 0.8);
    $Color3WithOpacity = hexToRGBA($Color3, 0.8);
    
// BEGIN WRITING PAGE HTML
    echo '<div class="hs_page">';

            /* This is a hidden div that knows if the event is over or not */
            $IsComplete = get_status();
            echo '<div id="EventStatus" class="hideme">' . $IsComplete . '</div>';
    
////// NEW STRUCTURE
                /* Container */
    $container_output =
    '<div class="hs_container">';
    echo $container_output;

    /* Wedding Info Container */
    $weddinginfocont_output =
        '<div class="hs_weddinginfocont">';
    echo $weddinginfocont_output;

    /* Banner */
    $svg_url = 'https://hitchstream.com/wp-content/themes/celebration-child/img/Chalk_WeddingOf.svg';
    $svg_content = file_get_contents($svg_url);
    $modified_svg = str_replace('<svg ', '<svg class="hs_banner_svg" style="fill:' . $Color3WithOpacity . ';" ', $svg_content);

    $banner_output = '
    <div class="hs_banner">
    ' . $modified_svg . '
    </div>';
    echo $banner_output;

    
    /* Heart */
    $svg_url = 'https://hitchstream.com/wp-content/themes/celebration-child/img/heart1.svg';
    $svg_content = file_get_contents($svg_url);
    $modified_svg = str_replace('<svg ', '<svg style="width:100px;fill:' . $Color1WithOpacity . ';" ', $svg_content);

    $banner_output = '
    <div class="hs_heart">
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
                    '<div class="hs_couplenamecont"><div id="hs_couplename" class="hs_couplename" style="color:' . $Color3WithOpacity . ';"><span class="firstname">' .
                    $Couplename1 .
                    " " .
                    $CouplenameSep .
                    " " .
                    '</span><span class="secondname">' .
                    $Couplename2 .
                    '</span></div></div>';
            }
            echo $couplenameoutput;
    




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
                '<div class="hs_days" data-text="' . $d_text . '" style="color:' . $Color3WithOpacity . ';"></div>';

            $countdownoutput .=
                '<div class="hs_hours"><div class="hs_countdown_numbers" style="color:' . $Color3WithOpacity . ';"><span class="n0"><span></span><span></span></span><span class="n1"><span></span><span></span></span></div><div class="hs_hours_text" style="color:' . $Color3WithOpacity . ';"><div class="hs_Seperator"></div><span>' .
                $h_text .
                "</span></div></div>";

            $countdownoutput .=
                '<div class="hs_minutes"><div class="hs_countdown_numbers" style="color:' . $Color3WithOpacity . ';"><span class="n0"><span></span><span></span></span><span class="n1"><span></span><span></span></span></div><div class="hs_minutes_text" style="color:' . $Color3WithOpacity . ';"><div class="hs_Seperator"></div><span>' .
                $m_text .
                "</span></div></div>";

            $countdownoutput .=
                '<div class="hs_seconds"><div class="hs_countdown_numbers" style="color:' . $Color3WithOpacity . ';"><span class="n0"><span></span><span></span></span><span class="n1"><span></span><span><span></span></div><div class="hs_seconds_text" style="color:' . $Color3WithOpacity . ';"><div class="hs_Seperator"></div><span>' .
                $s_text .
                "</span></div></div>";

            $countdownoutput .= "</div>";
            $countdownoutput .= "</div>";

            echo $countdownoutput;
    
        /* Seperator */
    $svg_url = 'https://hitchstream.com/wp-content/themes/celebration-child/img/chalkseperator1.svg';
    $svg_content = file_get_contents($svg_url);
    $modified_svg = str_replace('<svg ', '<svg class="hs_seperator_svg" style="fill:' . $Color3WithOpacity . ';" ', $svg_content);

    $seperator_output = '
    <div class="hs_seperator">
    ' . $modified_svg . '
    </div>';
    echo $seperator_output;


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
                '<div class="hs_dateandtime" style="color:' . $Color3WithOpacity . ';">' . $FormatDateTime . "</div>";
            echo $datetimeoutput;

    /* Venue */
    $venueoutput = '<div class="hs_venue" style="color:' . $Color3WithOpacity . ';"><a href = "' . $VenueUrl . '"> At ' . $Venuename . '</a></div>';
            echo $venueoutput;

    /* Socials */
    
        /* BirdLeft */
    $svg_url = 'https://hitchstream.com/wp-content/themes/celebration-child/img/birdleft.svg';
    $svg_content = file_get_contents($svg_url);
    $modified_svg = str_replace('<svg ', '<svg style="width:50px;fill:' . $Color1WithOpacity . ';" ', $svg_content);

    $birdleft_output = '
    <div class="hs_birdleft">
    ' . $modified_svg . '
    </div>';
    
    $svg_url = 'https://hitchstream.com/wp-content/themes/celebration-child/img/birdright.svg';
    $svg_content = file_get_contents($svg_url);
    $modified_svg = str_replace('<svg ', '<svg style="width:50px;fill:' . $Color1WithOpacity . ';" ', $svg_content);

    $birdright_output = '
    <div class="hs_birdright">
    ' . $modified_svg . '
    </div>';

    
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

            $socialiconsoutput = '<div class="socialcont">' . $birdleft_output . '<div id="hs_socials" class="hs_socials" style="color:' . $Color3WithOpacity . ';">';

            if ($SocialIcon1 != "") {
                $socialiconsoutput .= $icon1html;
            }

            if ($SocialIcon2 != "") {
                $socialiconsoutput .= $icon2html;
            }

            if ($SocialIcon3 != "") {
                $socialiconsoutput .= $icon3html;
            }

            $socialiconsoutput .= '</div>' . $birdright_output;
            echo $socialiconsoutput;

    /* End Wedding Info Container */
    echo '</div></div>';
      


    /* Couple Photo */
    $PhotoCaption = 
    '<div id="hs_beforetextcont" class="hs_beforetextcont">' .
        '<div id="hs_beforetext" class="hs_beforetext">' . $Beforetext . '</div>' .
    '</div>' .
    '<div id="hs_aftertextcont" class="hs_aftertextcont">' .
        '<div id="hs_aftertext" class="hs_aftertext">' . $Aftertext . '</div>' .
    '</div>';

    $couplephotooutput = 
    '<div class="hs_couplephoto"><div class="item">' . 
        '<div class="polaroid">' . 
            '<img src="' . $CoupleImageUrl . '">' . 
            '<div class="caption">' . $PhotoCaption . '</div>' . 
        '</div>' . 
    '</div></div>';

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
