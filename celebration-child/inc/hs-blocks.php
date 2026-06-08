<?php
/**
 * hs-blocks.php - child-owned copies of the HitchStream RapidComposer shortcode
 * handlers, ported VERBATIM from the parent Bold Themes plugin (celebration.php).
 *
 * WHY: every live event page is built from these [hs_*] shortcodes. They were
 * hand-added to the PARENT plugin, so a Bold Themes update would wipe them and
 * break every page. These child copies register on `init` (after the parent's
 * load-time registration), so add_shortcode() rebinds each tag to the child copy
 * - the pages keep rendering even if the parent is updated/reinstalled.
 *
 * Classes are byte-identical to the parent's; only the class name is prefixed
 * (HSCD_) to avoid a redeclare collision. Their one helper, hs_get_icon_html(),
 * and the status AJAX they rely on already live in this child theme.
 *
 * To REVERT to the parent handlers: remove the require in functions.php. Nothing
 * here is destructive; the database is never touched.
 */
if (!defined('ABSPATH')) { exit; }

class HSCD_hs_socials
{
    static function init()
    {
        add_shortcode("hs_socials", [__CLASS__, "handle_shortcode"]);
    }

    static function handle_shortcode($atts, $content)
    {
        extract(
            shortcode_atts(
                [
                    "icon1" => "",
                    "url1" => "",
                    "icon2" => "",
                    "url2" => "",
                    "icon3" => "",
                    "url3" => "",
                    "el_style" => "",
                    "el_class" => "",
                ],
                $atts,
                "hs_socials"
            )
        );

        $icon1 = sanitize_text_field($icon1);
        $icon2 = sanitize_text_field($icon2);
        $icon3 = sanitize_text_field($icon3);
        $el_style = sanitize_text_field($el_style);
        $el_class = sanitize_text_field($el_class);

        if ($el_class != "") {
            $class[] = $el_class;
        }

        if ($icon != "" && $icon != "no_icon") {
            $class[] = "hs_Ico";
        }

        $target = "_blank";

//        $icon1html = hs_get_icon_html($icon1, $url1, $el_class, $target, "");
//        $icon2html = hs_get_icon_html($icon2, $url2, $el_class, $target, "");
//        $icon3html = hs_get_icon_html($icon3, $url3, $el_class, $target, "");
//
//        $output = '<cont_socials><div id="hs_socials" class="hs_socials">';
//
//        if ($icon1 != "") {
//            $output .= $icon1html;
//        }
//
//        if ($icon2 != "") {
//            $output .= $icon2html;
//        }
//
//        if ($icon3 != "") {
//            $output .= $icon3html;
//        }
//
//        $output .= "</div></cont_socials>";
        
//        Simple Output
        
        $output = '<cont_socials>';

        if ($icon1 != "") {
            $output .= 'SocialUrl1=>' . $url1 . ',,SocialIcon1=>' . $icon1 . ',,';
        }

        if ($icon2 != "") {
            $output .= 'SocialUrl2=>' . $url2 . ',,SocialIcon2=>' . $icon2 . ',,';
        }

        if ($icon3 != "") {
            $output .= 'SocialUrl3=>' . $url3 . ',,SocialIcon3=>' . $icon3 . ',,';
        }

        $output .= "</cont_socials>";

        return $output;
    }
}

// [hs_pagetimezone]
class HSCD_hs_pagetimezone
{
    static function init()
    {
        add_shortcode("hs_pagetimezone", [__CLASS__, "handle_shortcode"]);
    }

    static function handle_shortcode($atts, $content)
    {
        extract(
            shortcode_atts(
                [
                    "hspagetimezone" => "",
                ],
                $atts,
                "hs_pagetimezone"
            )
        );

        $hspagetimezone = str_replace(" ", "", sanitize_text_field($hspagetimezone));
//        $hspagetimezone = sanitize_text_field($hspagetimezone);
        
        //Simple Output
                
        $output = '<cont_pagetimezone>,,pagetimezone=>' . $hspagetimezone . ',,</cont_pagetimezone>';
        return $output;
    }
}

// [hs_status]
class HSCD_hs_status
{
    static function init()
    {
        add_shortcode("hs_status", [__CLASS__, "handle_shortcode"]);
    }

    static function handle_shortcode($atts, $content)
    {
        extract(
            shortcode_atts(
                [
                    "hseventstatus" => "",
                ],
                $atts,
                "hs_status"
            )
        );

        $hseventstatus = sanitize_text_field($hseventstatus);

        //Simple Output
        $output = '<cont_status>' . $hseventstatus . '</cont_status>';

        return $output;
    }
}

// [hs_couplename]
class HSCD_hs_couplename
{
    static function init()
    {
        add_shortcode("hs_couplename", [__CLASS__, "handle_shortcode"]);
    }

    static function handle_shortcode($atts, $content)
    {
        extract(
            shortcode_atts(
                [
                    "hscouplename1" => "",
                    "hscouplename2" => "",
                    "hscouplenamesep" => "",
                    "hsnameimage" => "",
                    "el_class" => "",
                    "el_style" => "",
                ],
                $atts,
                "hs_couplename"
            )
        );

        $hscouplename = sanitize_text_field($hscouplename);
        $el_class = sanitize_text_field($el_class);
        $el_style = sanitize_text_field($el_style);
        

//        $style_attr = "";
//        if ($el_style != "") {
//            $style_attr = 'style="' . $el_style . '"';
//        }
//
//        $class_html = "";
//        if ($el_class != "") {
//            $class_html = " " . $el_class;
//        }
//
//        if ($image != "") {
//            $post_image = get_post($image);
//            if ($post_image) {
//                $caption = get_post($image)->post_excerpt;
//            }
//            $image = wp_get_attachment_image_src($image, $size);
//            $image = $image[0];
//            $output =
//                '<cont_couplename><div id="hs_couplename" class="hs_couplename' .
//                $class_html .
//                '"' .
//                $style_attr .
//                '><img src="' .
//                esc_url_raw($image) .
//                '" id="hs_couplenameimage" alt="' .
//                $hscouplename1 .
//                " " .
//                $hscouplenamesep .
//                " " .
//                $hscouplename2 .
//                '"></div></cont_couplename>';
//        } else {
//            $output =
//                '<cont_couplename><div id="hs_couplename" class="hs_couplename' .
//                $class_html .
//                '"' .
//                $style_html .
//                '><span class="firstname">' .
//                $hscouplename1 .
//                " " .
//                $hscouplenamesep .
//                " " .
//                '</span><span class="secondname">' .
//                $hscouplename2 .
//                '</span><div class="hs_seperator2"></div></div></cont_couplename>';
//        }
        
        //Simple Output
        
        if ($hsnameimage != "") {
            $post_image = get_post($hsnameimage);
            if ($post_image) {
                $caption = get_post($hsnameimage)->post_excerpt;
            }
            $hsnameimage = wp_get_attachment_image_src($hsnameimage, $size);
            $hsnameimage = $hsnameimage[0]; 
        }
            
        $output = '<cont_couplename>';
        if ($hsnameimage != "") {
            $output .='NameImageUrl=>' . $hsnameimage . ',,';
        }
        if ($hscouplename1 != "") {
            $output .='Couplename1=>' . $hscouplename1 . ',,';
        }
        if ($hscouplename2 != "") {
            $output .='Couplename2=>' . $hscouplename2 . ',,';
        }
        if ($hscouplenamesep != "") {
            $output .='CouplenameSep=>' . $hscouplenamesep . ',,';
        }
        
        $output .= "</cont_couplename>";
        return $output;
    }
}

// [hs_player]
class HSCD_hs_player
{
    static function init()
    {
        add_shortcode("hs_player", [__CLASS__, "handle_shortcode"]);
    }

    static function handle_shortcode($atts, $content)
    {
        extract(
            shortcode_atts(
                [
                    "videotype" => "",
                    "cfid" => "",
                    "yturl" => "",
                    "initialposter" => "",
                    "idleposter" => "",
                    "el_class" => "",
                ],
                $atts,
                "hs_player"
            )
        );

        $videotype = sanitize_text_field($videotype);
        $cfid = sanitize_text_field($cfid);
        $yturl = sanitize_text_field($yturl);
        $el_class = sanitize_text_field($el_class);
        $initialposter_url = $idleposter_url = '';

        if ($initialposter != "") {
            $post_image = get_post($initialposter);
            $caption = ""; // Initialize caption variable
            if ($post_image) {
                $caption = $post_image->post_excerpt; // Get the caption if needed
            }
            $initialposter_url = wp_get_attachment_image_src($initialposter, 'full'); // Assuming you want the full image
            $initialposter_url = $initialposter_url[0]; // Get the URL of the image
            $initialposter_url = urlencode($initialposter_url); // URL encode the image URL
        }

        if ($idleposter != "") {
            $post_image = get_post($idleposter);
            $caption = ""; // Initialize caption variable
            if ($post_image) {
                $caption = $post_image->post_excerpt; // Get the caption if needed
            }
            $idleposter_url = wp_get_attachment_image_src($idleposter, 'full'); // Assuming you want the full image
            $idleposter_url = $idleposter_url[0]; // Get the URL of the image
            $idleposter_url = urlencode($idleposter_url); // URL encode the image URL
        }

        $class_html = "";
        if ($el_class != "") {
            $class_html = " " . $el_class;
        }

        if ($videotype === "yt") {
            $playerurl = $yturl;
        } else {
            $playerurl = 'https://hitchstream.com/player?live=' . ($videotype === "vod" ? 'false' : 'true') . '&inputId=' . urlencode($cfid);

            if ($videotype !== "vod") {
                if (!empty($initialposter_url)) {
                    $playerurl .= '&initialposterURL=' . $initialposter_url; // Already URL encoded
                }
                if (!empty($idleposter_url)) {
                    $playerurl .= '&idleposterURL=' . $idleposter_url; // Already URL encoded
                }
            }
        }

        // Wrap player URL in a container to be read on the page.
        $output = '<cont_player>PlayerURL=>' . esc_url_raw($playerurl) . ',,</cont_player>';

        return $output;
    }
}


// [hs_beforetext]
class HSCD_hs_beforetext
{
    static function init()
    {
        add_shortcode("hs_beforetext", [__CLASS__, "handle_shortcode"]);
    }

    static function handle_shortcode($atts, $content)
    {
        extract(
            shortcode_atts(
                [
                    "hsbeforetext" => "",
                    "el_class" => "",
                    "el_style" => "",
                ],
                $atts,
                "hs_beforetext"
            )
        );

        $hsbeforetext = sanitize_text_field($hsbeforetext);
        $el_class = sanitize_text_field($el_class);
        $el_style = sanitize_text_field($el_style);

        $style_attr = "";
        if ($el_style != "") {
            $style_attr = 'style="' . $el_style . '"';
        }

        $class_html = "";
        if ($el_class != "") {
            $class_html = " " . $el_class;
        }
        
        //Simple Output
            $output =
            '<cont_beforetext>Beforetext=>' . $hsbeforetext . ',,</cont_beforetext>';

        return $output;
    }
}

// [hs_eventtitle]
class HSCD_hs_eventtitle
{
    static function init()
    {
        add_shortcode("hs_eventtitle", [__CLASS__, "handle_shortcode"]);
    }

    static function handle_shortcode($atts, $content)
    {
        extract(
            shortcode_atts(
                [
                    "hseventtitle" => "",
                    "el_class" => "",
                    "el_style" => "",
                ],
                $atts,
                "hs_eventtitle"
            )
        );

        $hseventtitle = sanitize_text_field($hseventtitle);
        $el_class = sanitize_text_field($el_class);
        $el_style = sanitize_text_field($el_style);

        $style_attr = "";
        if ($el_style != "") {
            $style_attr = 'style="' . $el_style . '"';
        }

        $class_html = "";
        if ($el_class != "") {
            $class_html = " " . $el_class;
        }
        
        //Simple Output
            $output =
            '<cont_eventtitle>Eventtitle=>' . $hseventtitle . ',,</cont_eventtitle>';

        return $output;
    }
}

// [hs_venueevent]
class HSCD_hs_venueevent
{
    static function init()
    {
        add_shortcode("hs_venueevent", [__CLASS__, "handle_shortcode"]);
    }

    static function handle_shortcode($atts, $content)
    {
        extract(
            shortcode_atts(
                [
                    "hsvenueevent" => "",
                    "el_class" => "",
                    "el_style" => "",
                ],
                $atts,
                "hs_venueevent"
            )
        );

        $hsbeforetext = sanitize_text_field($hsbeforetext);
        $el_class = sanitize_text_field($el_class);
        $el_style = sanitize_text_field($el_style);

        $style_attr = "";
        if ($el_style != "") {
            $style_attr = 'style="' . $el_style . '"';
        }

        $class_html = "";
        if ($el_class != "") {
            $class_html = " " . $el_class;
        }
        
        //Simple Output
            $output =
            '<cont_venueevent>Venueevent=>' . $hsvenueevent . ',,</cont_venueevent>';

        return $output;
    }
}

// [hs_venue]
class HSCD_hs_venue
{
    static function init()
    {
        add_shortcode("hs_venue", [__CLASS__, "handle_shortcode"]);
    }

    static function handle_shortcode($atts, $content)
    {
        extract(
            shortcode_atts(
                [
                    "hsvenue" => "",
                    "hsvenuesite" => "",
                ],
                $atts,
                "hs_venue"
            )
        );

        $hsvenue = sanitize_text_field($hsvenue);
        $hsvenuesite = sanitize_text_field($hsvenuesite);

        
        //Simple Output
            $output =
            '<cont_venue>,,Venuename=>' . $hsvenue . ',,VenueUrl=>' . $hsvenuesite . ',,</cont_venue>';

        return $output;
    }
}

// [hs_colors]
class HSCD_hs_colors
{
    static function init()
    {
        add_shortcode("hs_colors", [__CLASS__, "handle_shortcode"]);
    }

    static function handle_shortcode($atts, $content)
    {
        extract(
            shortcode_atts(
                [
                    "color1" => "",
                    "color2" => "",
                    "color3" => "",
                ],
                $atts,
                "hs_colors"
            )
        );

        $color1 = str_replace(" ", "", sanitize_text_field($color1));
        $color2 = str_replace(" ", "", sanitize_text_field($color2));
        $color3 = str_replace(" ", "", sanitize_text_field($color3));
        
        //Simple Output
                $output =
            '<cont_colors>Color1=>' . $color1 . ',,Color2=>' . $color2 . ',,Color3=>' . $color3 . '</cont_colors>';

        return $output;
    }
}

// [hs_aftertext]
class HSCD_hs_aftertext
{
    static function init()
    {
        add_shortcode("hs_aftertext", [__CLASS__, "handle_shortcode"]);
    }

    static function handle_shortcode($atts, $content)
    {
        extract(
            shortcode_atts(
                [
                    "hsaftertext" => "",
                    "el_class" => "",
                    "el_style" => "",
                ],
                $atts,
                "hs_aftertext"
            )
        );

        $hsaftertext = sanitize_text_field($hsaftertext);
        $el_class = sanitize_text_field($el_class);
        $el_style = sanitize_text_field($el_style);

        $style_attr = "";
        if ($el_style != "") {
            $style_attr = 'style="' . $el_style . '"';
        }

        $class_html = "";
        if ($el_class != "") {
            $class_html = " " . $el_class;
        }

//        $output =
//            '<cont_aftertext><div id="hs_aftertext" class="hs_aftertext' .
//            $class_html .
//            '"' .
//            $style_attr .
//            ">" .
//            $hsaftertext .
//            "</div></cont_aftertext>";
        
        //Simple Output
            $output =
            '<cont_aftertext>Aftertext=>' . $hsaftertext . ',,</cont_aftertext>'; 

        return $output;
    }
}

// [hs_countdown]
class HSCD_hs_datetime
{
    static function init()
    {
        add_shortcode("hs_datetime", [__CLASS__, "handle_shortcode"]);
    }

    static function handle_shortcode($atts, $content)
    {
        extract(
            shortcode_atts(
                [
                    "datetime" => "",
//                    "hide_indication" => "",
//                    "show_seconds" => "",
                    "el_style" => "",
                    "el_class" => "",
                ],
                $atts,
                "hs_datetime"
            )
        );

        $datetime = sanitize_text_field($datetime);
//        $hide_indication = sanitize_text_field($hide_indication);
//        $show_seconds = sanitize_text_field($show_seconds);
        $el_style = sanitize_text_field($el_style);
        $el_class = sanitize_text_field($el_class);

        $style_attr = "";
        if ($el_style != "") {
            $style_attr = ' style="' . $el_style . '"';
        }

        $el_class = [];
        $el_class[] = "hs_CounterHolder";

        $datetime = sanitize_text_field($datetime);

//        $target = strtotime($datetime);
//        $now = strtotime("now");
//
//        $init_seconds = $target - $now;
//        if ($init_seconds < 0) {
//            $init_seconds = 0;
//        }
//
//        $d_text = __("Days", "bt_plugin");
//        $h_text = __("Hours", "bt_plugin");
//        $m_text = __("Minutes", "bt_plugin");
//        $s_text = __("Seconds", "bt_plugin");
//
//        if ($hide_indication == "yes") {
//            $d_text = "";
//            $h_text = "";
//            $m_text = "";
//            $s_text = "";
//        }
//
//        $output =
//            '<cont_countdown><div id="hs_counter" class="' .
//            implode(" ", $el_class) .
//            '"' .
//            $style_attr .
//            ">";
//        $output .=
//            '<div class="hs_CountdownHolder" data-init-seconds="' .
//            $init_seconds .
//            '">';
//
//        $output .= '<div class="hs_days" data-text="' . $d_text . '"></div>';
//
//        $output .=
//            '<div class="hs_hours"><div class="hs_countdown_numbers"><span class="n0"><span></span><span></span></span><span class="n1"><span></span><span></span></span></div><div class="hs_hours_text"><div class="hs_Seperator"></div><span>' .
//            $h_text .
//            "</span></div></div>";
//
//        $output .=
//            '<div class="hs_minutes"><div class="hs_countdown_numbers"><span class="n0"><span></span><span></span></span><span class="n1"><span></span><span></span></span></div><div class="hs_minutes_text"><div class="hs_Seperator"></div><span>' .
//            $m_text .
//            "</span></div></div>";
//
//        if ($show_seconds == "yes") {
//            $output .=
//                '<div class="hs_seconds"><div class="hs_countdown_numbers"><span class="n0"><span></span><span></span></span><span class="n1"><span></span><span><span></span></div><div class="hs_seconds_text"><div class="hs_Seperator"></div><span>' .
//                $s_text .
//                "</span></div></div>";
//        }
//        $output .= "</div>";
//        $output .= "</div></cont_countdown>";
        
        //Simple Output
        
                $output = '<cont_datetime>DateTime=>' . $datetime . ',,</cont_datetime>';
        

        return $output;
    }
}


// [hs_image]
class HSCD_hs_image
{
    static function init()
    {
        add_shortcode("hs_image", [__CLASS__, "handle_shortcode"]);
    }

    static function handle_shortcode($atts, $content)
    {
        extract(
            shortcode_atts(
                [
                    "image" => "",
                    "alt_text" => "",
                    "el_style" => "",
                    "el_class" => "",
                ],
                $atts,
                "hs_image"
            )
        );

        $alt_text = sanitize_text_field($alt_text);
        $el_style = sanitize_text_field($el_style);
        $el_class = sanitize_text_field($el_class);

        if ($image != "") {
            $post_image = get_post($image);
            if ($post_image) {
                $caption = get_post($image)->post_excerpt;
            }
            $image = wp_get_attachment_image_src($image, $size);
            $image = $image[0];
        }

        $style_attr = "";
        if ($el_style != "") {
            $style_attr = 'style="' . $el_style . '"';
        }

        $class_html = "";
        if ($el_class != "") {
            $class_html = " " . $el_class;
        }

        //Simple Output
                $output =
            '<cont_coupleimage>CoupleImageUrl=>' . esc_url_raw($image) . ',,</cont_coupleimage>';

        return $output;
    }
}

// [hs_background]
class HSCD_hs_background
{
    static function init()
    {
        add_shortcode("hs_background", [__CLASS__, "handle_shortcode"]);
    }

    static function handle_shortcode($atts, $content)
    {
        extract(
            shortcode_atts(
                [
                    "bgimage" => "",
                    "bgcolor" => "",
                    "el_style" => "",
                    "el_class" => "",
                ],
                $atts,
                "hs_background"
            )
        );

        $bgcolor = str_replace(" ", "", sanitize_text_field($bgcolor));
        $el_style = sanitize_text_field($el_style);
        $el_class = sanitize_text_field($el_class);
        

        if ($bgimage != "") {
            $post_image = get_post($bgimage);
            if ($post_image) {
                $caption = get_post($bgimage)->post_excerpt;
            }
            $bgimage = wp_get_attachment_image_src($bgimage, $size);
            $bgimage = $bgimage[0];
        }

        $style_attr = "";
        if ($el_style != "") {
            $style_attr = 'style="' . $el_style . '"';
        }

        $class_html = "";
        if ($el_class != "") {
            $class_html = " " . $el_class;
        }

        //Simple Output
                $output =
            '<cont_background>BackgroundImageUrl=>' . esc_url_raw($bgimage) . ',,BackgroundColor=>' . $bgcolor . '</cont_background>';

        return $output;
    }
}

// [hs_venuelogo]
class HSCD_hs_venuelogo
{
    static function init()
    {
        add_shortcode("hs_venuelogo", [__CLASS__, "handle_shortcode"]);
    }

    static function handle_shortcode($atts, $content)
    {
        extract(
            shortcode_atts(
                [
                    "image" => "",
                    "alt_text" => "",
                    "el_style" => "",
                    "el_class" => "",
                ],
                $atts,
                "hs_venuelogo"
            )
        );

        $alt_text = sanitize_text_field($alt_text);
        $el_style = sanitize_text_field($el_style);
        $el_class = sanitize_text_field($el_class);

        if ($image != "") {
            $post_image = get_post($image);
            if ($post_image) {
                $caption = get_post($image)->post_excerpt;
            }
            $image = wp_get_attachment_image_src($image, $size);
            $image = $image[0];
        }

        $style_attr = "";
        if ($el_style != "") {
            $style_attr = 'style="' . $el_style . '"';
        }

        $class_html = "";
        if ($el_class != "") {
            $class_html = " " . $el_class;
        }

        //Simple Output
                $output =
            '<cont_venuelogo>VenueLogoImageUrl=>' . esc_url_raw($image) . ',,</cont_venuelogo>';

        return $output;
    }
}

// [hs_venuephoto]
class HSCD_hs_venuephoto
{
    static function init()
    {
        add_shortcode("hs_venuephoto", [__CLASS__, "handle_shortcode"]);
    }

    static function handle_shortcode($atts, $content)
    {
        extract(
            shortcode_atts(
                [
                    "image" => "",
                    "alt_text" => "",
                    "el_style" => "",
                    "el_class" => "",
                ],
                $atts,
                "hs_venuephoto"
            )
        );

        $alt_text = sanitize_text_field($alt_text);
        $el_style = sanitize_text_field($el_style);
        $el_class = sanitize_text_field($el_class);

        if ($image != "") {
            $post_image = get_post($image);
            if ($post_image) {
                $caption = get_post($image)->post_excerpt;
            }
            $image = wp_get_attachment_image_src($image, $size);
            $image = $image[0];
        }

        $style_attr = "";
        if ($el_style != "") {
            $style_attr = 'style="' . $el_style . '"';
        }

        $class_html = "";
        if ($el_class != "") {
            $class_html = " " . $el_class;
        }

        //Simple Output
                $output =
            '<cont_venuephoto>VenuePhotoImageUrl=>' . esc_url_raw($image) . ',,</cont_venuephoto>';

        return $output;
    }
}


/**
 * Register AFTER the parent (which registers at plugin-load) so each tag rebinds
 * to the child copy. Priority 20 for extra safety.
 */
add_action('init', function () {
    HSCD_hs_socials::init();
    HSCD_hs_pagetimezone::init();
    HSCD_hs_status::init();
    HSCD_hs_couplename::init();
    HSCD_hs_player::init();
    HSCD_hs_beforetext::init();
    HSCD_hs_eventtitle::init();
    HSCD_hs_venueevent::init();
    HSCD_hs_venue::init();
    HSCD_hs_colors::init();
    HSCD_hs_aftertext::init();
    HSCD_hs_datetime::init();
    HSCD_hs_image::init();
    HSCD_hs_background::init();
    HSCD_hs_venuelogo::init();
    HSCD_hs_venuephoto::init();
}, 20);
