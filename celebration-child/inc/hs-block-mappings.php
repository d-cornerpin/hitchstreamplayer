<?php
/**
 * hs-block-mappings.php - Tier 2: child-owned RapidComposer builder mappings for
 * the 16 hs_ blocks (so they stay EDITABLE in the visual builder even if the parent
 * plugin is updated/reinstalled; rendering is already covered by inc/hs-blocks.php).
 *
 * bt_rc_map() writes to BT_RC_Root::$elements, which the builder reads on admin_head
 * (post-edit screen) - so registering on admin_init is early enough. Guarded by
 * function_exists('bt_rc_map') so it no-ops if RapidComposer isn't active. Verbatim
 * copy of the parent's hs_ mappings; only $icon_arr (used by hs_socials) is rebuilt
 * here, degrading to empty if the parent plugin's icon lists are gone.
 */
if (!defined('ABSPATH')) { exit; }

add_action('admin_init', 'hs_register_block_mappings');

if (!function_exists('hs_register_block_mappings')) {
function hs_register_block_mappings() {
    if (!function_exists('bt_rc_map')) { return; } // RapidComposer not active

    // Icon options for hs_socials' pickers - from the parent plugin's lists if present.
    $icon_arr = array();
    if (function_exists('bt_fa_icons') && function_exists('bt_s7_icons') && function_exists('bt_custom_icons')) {
        $icon_arr = array(
            'Font Awesome' => bt_fa_icons(),
            'S7'           => bt_s7_icons(),
            'Custom'       => bt_custom_icons(),
        );
    }

        bt_rc_map("hs_image", [
            "name" => __("HS Couple Image", "bt_plugin"),
            "description" => __("Single image", "bt_plugin"),
            "params" => [
                [
                    "param_name" => "image",
                    "type" => "attach_image",
                    "heading" => __("Photo of Couple", "bt_plugin"),
                    "preview" => true,
                ],
                [
                    "param_name" => "alt_text",
                    "type" => "textfield",
                    "heading" => __("Alt Text or Couple Name", "bt_plugin"),
                    "preview" => true,
                ],
                [
                    "param_name" => "el_class",
                    "type" => "textfield",
                    "heading" => __("Extra Class Name(s)", "bt_plugin"),
                ],
                [
                    "param_name" => "el_style",
                    "type" => "textfield",
                    "heading" => __("Inline Style", "bt_plugin"),
                ],
            ],
        ]);
        
        bt_rc_map("hs_background", [
            "name" => __("HS Background", "bt_plugin"),
            "description" => __("Single image", "bt_plugin"),
            "params" => [
                [
                    "param_name" => "bgimage",
                    "type" => "attach_image",
                    "heading" => __("Background Image", "bt_plugin"),
                    "preview" => true,
                ],
                [
                    "param_name" => "bgcolor",
                    "type" => "colorpicker",
                    "heading" => __("Main Color", "bt_plugin"),
                    "description" => __("Background Color"),
                    "preview" => true,
                ],
                [
                    "param_name" => "el_class",
                    "type" => "textfield",
                    "heading" => __("Extra Class Name(s)", "bt_plugin"),
                ],
                [
                    "param_name" => "el_style",
                    "type" => "textfield",
                    "heading" => __("Inline Style", "bt_plugin"),
                ],
            ],
        ]);
        
        bt_rc_map("hs_venuelogo", [
            "name" => __("HS Venue Logo", "bt_plugin"),
            "description" => __("Venue Logo Image", "bt_plugin"),
            "params" => [
                [
                    "param_name" => "image",
                    "type" => "attach_image",
                    "heading" => __("Venue Logo", "bt_plugin"),
                    "preview" => true,
                ],
                [
                    "param_name" => "alt_text",
                    "type" => "textfield",
                    "heading" => __("Alt Text or Venue Name", "bt_plugin"),
                    "preview" => true,
                ],
                [
                    "param_name" => "el_class",
                    "type" => "textfield",
                    "heading" => __("Extra Class Name(s)", "bt_plugin"),
                ],
                [
                    "param_name" => "el_style",
                    "type" => "textfield",
                    "heading" => __("Inline Style", "bt_plugin"),
                ],
            ],
        ]);
        
        bt_rc_map("hs_venuephoto", [
            "name" => __("HS Venue Photo", "bt_plugin"),
            "description" => __("Venue Photo", "bt_plugin"),
            "params" => [
                [
                    "param_name" => "image",
                    "type" => "attach_image",
                    "heading" => __("Venue Photo", "bt_plugin"),
                    "preview" => true,
                ],
                [
                    "param_name" => "alt_text",
                    "type" => "textfield",
                    "heading" => __("Alt Text or Venue Name", "bt_plugin"),
                    "preview" => true,
                ],
                [
                    "param_name" => "el_class",
                    "type" => "textfield",
                    "heading" => __("Extra Class Name(s)", "bt_plugin"),
                ],
                [
                    "param_name" => "el_style",
                    "type" => "textfield",
                    "heading" => __("Inline Style", "bt_plugin"),
                ],
            ],
        ]);
        
        bt_rc_map("hs_pagetimezone", [
            "name" => __("HS Time Zone", "bt_plugin"),
            "description" => __("HS Time Zone", "bt_plugin"),
            "params" => [
                [
                    "param_name" => "hspagetimezone",
                    "type" => "dropdown",
                    "heading" => __("Choose pagetimezone", "bt_plugin"),
                    "preview" => true,
                    "value" => [
                        __("Pacific", "bt_plugin") => "pacific",
                        __("Mountain", "bt_plugin") => "mountain",
                        __("Central", "bt_plugin") => "central",
                        __("Eastern", "bt_plugin") => "eastern",
                    ],
                ],
            ],
        ]);
        
        bt_rc_map("hs_status", [
            "name" => __("HS Event Status", "bt_plugin"),
            "description" => __("HS Event Status", "bt_plugin"),
            "params" => [
                [
                    "param_name" => "hseventstatus",
                    "type" => "dropdown",
                    "heading" => __("Has this event finished?", "bt_plugin"),
                    "preview" => true,
                    "value" => [
                        __("Yep, it is done!", "bt_plugin") => "complete",
                        __("Not yet!", "bt_plugin") => "not complete",
                    ],
                ],
            ],
        ]);
        
        bt_rc_map("hs_couplename", [
            "name" => __("HS Couple Name", "bt_plugin"),
            "description" => __("HS Couple Name", "bt_plugin"),
            "params" => [
                [
                    "param_name" => "hscouplename1",
                    "type" => "textfield",
                    "heading" => __("Person 1 Name", "bt_plugin"),
                    "preview" => true,
                ],
                [
                    "param_name" => "hscouplenamesep",
                    "type" => "textfield",
                    "heading" => __("Seperator", "bt_plugin"),
                    "preview" => true,
                ],
                [
                    "param_name" => "hscouplename2",
                    "type" => "textfield",
                    "heading" => __("Person 2 Name", "bt_plugin"),
                    "preview" => true,
                ],
                [
                    "param_name" => "hsnameimage",
                    "type" => "attach_image",
                    "heading" => __("Couple Name Image", "bt_plugin"),
                    "description" => __("Optional Couple Name Image"),
                    "preview" => true,
                ],
                [
                    "param_name" => "el_class",
                    "type" => "textfield",
                    "heading" => __("Extra Class Name(s)", "bt_plugin"),
                ],
                [
                    "param_name" => "el_style",
                    "type" => "textfield",
                    "heading" => __("Inline Style", "bt_plugin"),
                ],
            ],
        ]);

        bt_rc_map("hs_player", [
            "name" => __("HS Player", "bt_plugin"),
            "description" => __("HS Player Settings", "bt_plugin"),
            "params" => [
                [
                    "param_name" => "videotype",
                    "type" => "dropdown",
                    "heading" => __("Video Type", "bt_plugin"),
                    "preview" => true,
                    "value" => [
                        __("Live", "bt_plugin") => "live",
                        __("VOD", "bt_plugin") => "vod",
                        __("YouTube", "bt_plugin") => "yt",
                    ],
                ],
                [
                    "param_name" => "cfid",
                    "type" => "textfield",
                    "heading" => __("CloudFlare inputID", "bt_plugin"),
                ],
                [
                    "param_name" => "yturl",
                    "type" => "textfield",
                    "heading" => __("YouTube URL", "bt_plugin"),
                ],
                [
                    "param_name" => "initialposter",
                    "type" => "attach_image",
                    "heading" => __("Initial Poster", "bt_plugin"),
                    "preview" => true,
                ],
                [
                    "param_name" => "idleposter",
                    "type" => "attach_image",
                    "heading" => __("Idle Poster", "bt_plugin"),
                    "preview" => true,
                ],
                [
                    "param_name" => "el_class",
                    "type" => "textfield",
                    "heading" => __("Extra Class Name(s)", "bt_plugin"),
                ],
            ],
        ]);
        
        bt_rc_map("hs_beforetext", [
            "name" => __("HS Before Text", "bt_plugin"),
            "description" => __("Text to show before wedding.", "bt_plugin"),
            "params" => [
                [
                    "param_name" => "hsbeforetext",
                    "type" => "textfield",
                    "heading" => __("Before Text", "bt_plugin"),
                    "description" => __("Try to keep it under 200 characters."),
                    "preview" => true,
                ],
                [
                    "param_name" => "el_class",
                    "type" => "textfield",
                    "heading" => __("Extra Class Name(s)", "bt_plugin"),
                ],
                [
                    "param_name" => "el_style",
                    "type" => "textfield",
                    "heading" => __("Inline Style", "bt_plugin"),
                ],
            ],
        ]);
        
        bt_rc_map("hs_eventtitle", [
            "name" => __("HS Event Title", "bt_plugin"),
            "description" => __("Event Title Text", "bt_plugin"),
            "params" => [
                [
                    "param_name" => "hseventtitle",
                    "type" => "textfield",
                    "heading" => __("Event Title", "bt_plugin"),
                    "description" => __("Try to keep it under 50 characters."),
                    "preview" => true,
                ],
                [
                    "param_name" => "el_class",
                    "type" => "textfield",
                    "heading" => __("Extra Class Name(s)", "bt_plugin"),
                ],
                [
                    "param_name" => "el_style",
                    "type" => "textfield",
                    "heading" => __("Inline Style", "bt_plugin"),
                ],
            ],
        ]);
        
        bt_rc_map("hs_venueevent", [
            "name" => __("HS Venue Event Title", "bt_plugin"),
            "description" => __("Title of Venue Event", "bt_plugin"),
            "params" => [
                [
                    "param_name" => "hsvenueevent",
                    "type" => "textfield",
                    "heading" => __("Venue Event Title", "bt_plugin"),
                    "description" => __("Try to keep it under 30 characters."),
                    "preview" => true,
                ],
                [
                    "param_name" => "el_class",
                    "type" => "textfield",
                    "heading" => __("Extra Class Name(s)", "bt_plugin"),
                ],
                [
                    "param_name" => "el_style",
                    "type" => "textfield",
                    "heading" => __("Inline Style", "bt_plugin"),
                ],
            ],
        ]);

        bt_rc_map("hs_aftertext", [
            "name" => __("HS After Text", "bt_plugin"),
            "description" => __("Text to show during stream.", "bt_plugin"),
            "params" => [
                [
                    "param_name" => "hsaftertext",
                    "type" => "textfield",
                    "heading" => __("After Text", "bt_plugin"),
                    "description" => __("Try to keep it under 200 characters."),
                    "preview" => true,
                ],
                [
                    "param_name" => "el_class",
                    "type" => "textfield",
                    "heading" => __("Extra Class Name(s)", "bt_plugin"),
                ],
                [
                    "param_name" => "el_style",
                    "type" => "textfield",
                    "heading" => __("Inline Style", "bt_plugin"),
                ],
            ],
        ]);
        
        bt_rc_map("hs_venue", [
            "name" => __("HS Venue", "bt_plugin"),
            "description" => __("Wedding Venue", "bt_plugin"),
            "params" => [
                [
                    "param_name" => "hsvenue",
                    "type" => "textfield",
                    "heading" => __("Venue Name", "bt_plugin"),
                    "preview" => true,
                ],
                [
                    "param_name" => "hsvenuesite",
                    "type" => "textfield",
                    "heading" => __("Venue Website URL", "bt_plugin"),
                    "preview" => true,
                ],
            ],
        ]);

        bt_rc_map("hs_colors", [
            "name" => __("HS Colors", "bt_plugin"),
            "description" => __("Wedding colors", "bt_plugin"),
            "params" => [
                [
                    "param_name" => "color1",
                    "type" => "colorpicker",
                    "heading" => __("Main Color", "bt_plugin"),
                    "description" => __("Used as main color in theme."),
                    "preview" => true,
                ],
                [
                    "param_name" => "color2",
                    "type" => "colorpicker",
                    "heading" => __("Secondary Color", "bt_plugin"),
                    "description" => __(
                        "Used as secondary color of theme calls for it."
                    ),
                    "preview" => true,
                ],
                [
                    "param_name" => "color3",
                    "type" => "colorpicker",
                    "heading" => __("Highlight Color", "bt_plugin"),
                    "description" => __(
                        "Used as highlight color if theme calls for it."
                    ),
                    "preview" => true,
                ],
            ],
        ]);
        
        bt_rc_map("hs_socials", [
            "name" => __("HS Socials", "bt_plugin"),
            "description" => __("Social Network Links", "bt_plugin"),
            "params" => [
                [
                    "param_name" => "icon1",
                    "type" => "iconpicker",
                    "heading" => __("Icon 1", "bt_plugin"),
                    "value" => $icon_arr,
                    "preview" => true,
                ],
                [
                    "param_name" => "url1",
                    "type" => "textfield",
                    "heading" => __("URL 1", "bt_plugin"),
                ],
                [
                    "param_name" => "icon2",
                    "type" => "iconpicker",
                    "heading" => __("Icon 2", "bt_plugin"),
                    "value" => $icon_arr,
                    "preview" => true,
                ],
                [
                    "param_name" => "url2",
                    "type" => "textfield",
                    "heading" => __("URL 2", "bt_plugin"),
                ],
                [
                    "param_name" => "icon3",
                    "type" => "iconpicker",
                    "heading" => __("Icon 3", "bt_plugin"),
                    "value" => $icon_arr,
                    "preview" => true,
                ],
                [
                    "param_name" => "url3",
                    "type" => "textfield",
                    "heading" => __("URL 3", "bt_plugin"),
                ],

                [
                    "param_name" => "el_class",
                    "type" => "textfield",
                    "heading" => __("Extra Class Name(s)", "bt_plugin"),
                ],
                [
                    "param_name" => "el_style",
                    "type" => "textfield",
                    "heading" => __("Inline Style", "bt_plugin"),
                ],
            ],
        ]);
        
        bt_rc_map("hs_datetime", [
            "name" => __("HS DateTime", "bt_plugin"),
            "description" => __("HS Date and Time", "bt_plugin"),
            "params" => [
                [
                    "param_name" => "datetime",
                    "type" => "textfield",
                    "heading" => __("Target Date and Time", "bt_plugin"),
                    "description" => __(
                        "Mountain Time  - Format e.g. 11/14/2022 22:45:00"
                    ),
                    "preview" => true,
                ],
//                [
//                    "param_name" => "hide_indication",
//                    "type" => "dropdown",
//                    "heading" => __("Hide Indication", "bt_plugin"),
//                    "description" => __(
//                        "Hide indication of days, hours, minutes and seconds",
//                        "bt_plugin"
//                    ),
//                    "value" => [
//                        __("No", "bt_plugin") => "no",
//                        __("Yes", "bt_plugin") => "yes",
//                    ],
//                ],
//                [
//                    "param_name" => "show_seconds",
//                    "type" => "dropdown",
//                    "heading" => __("Show Seconds", "bt_plugin"),
//                    "description" => __(
//                        "Show the seconds in the countdown",
//                        "bt_plugin"
//                    ),
//                    "value" => [
//                        __("Yes", "bt_plugin") => "yes",
//                        __("No", "bt_plugin") => "no",
//                    ],
//                ],
                [
                    "param_name" => "el_class",
                    "type" => "textfield",
                    "heading" => __("Extra Class Name(s)", "bt_plugin"),
                ],
                [
                    "param_name" => "el_style",
                    "type" => "textfield",
                    "heading" => __("Inline Style", "bt_plugin"),
                ],
            ],
        ]);        

}
}
