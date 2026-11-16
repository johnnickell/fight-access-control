"""Render the bounded native Feature runtime view; no application or network work."""
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont

REVISION = "48c6f6a1cb8d42f0f7869bc6fdb100e582cbcfa9"
STATE = "PROPOSED TASK-00175 on base"
image = Image.new("RGB", (1280, 1130), "white")
draw = ImageDraw.Draw(image)
font_path = "/System/Library/Fonts/Supplemental/Arial.ttf"
font = ImageFont.truetype(font_path, 23)
small = ImageFont.truetype(font_path, 19)
title = ImageFont.truetype(font_path, 29)
draw.text((35, 24), "CI4 Feature admission: selected ordinary JSON path", font=title, fill="#172b4d")
draw.text((35, 68), f"{STATE}: {REVISION}", font=small, fill="#172b4d")


def box(bounds, text, color="#e8f0fe"):
    draw.rounded_rectangle(bounds, radius=12, fill=color, outline="#253858", width=2)
    draw.multiline_text((bounds[0] + 18, bounds[1] + 15), text, font=font, fill="#172b4d", spacing=6)


def arrow(start, end, label, at):
    x1, y1 = start
    x2, y2 = end
    length = ((x2 - x1) ** 2 + (y2 - y1) ** 2) ** 0.5
    dx, dy = (x2 - x1) / length, (y2 - y1) / length
    for distance in range(0, int(length) - 14, 13):
        draw.line((x1 + dx * distance, y1 + dy * distance,
                   x1 + dx * (distance + 6), y1 + dy * (distance + 6)), fill="#253858", width=3)
    draw.polygon([(x2, y2), (x2 - dx * 15 - dy * 7, y2 - dy * 15 + dx * 7),
                  (x2 - dx * 15 + dy * 7, y2 - dy * 15 - dx * 7)], fill="#253858")
    draw.text(at, label, font=small, fill="#172b4d")


box((45, 120, 800, 210), "Consumer entry reset, then native explicit Router\nCurrent matched controller or closure and route options")
box((45, 270, 800, 370), "FeatureFilter.before / FeatureRequirements\nBegin scope, verify effective selected code")
box((45, 430, 800, 530), "RequestAuthentication.authority\nRequest + router identity, one real User/Agent provider")
box((45, 590, 800, 680), "Application FeatureAdmission.admit\nConjunction using a concrete snapshot or deliberate null")
box((45, 740, 800, 835), "Application FeatureAvailability.isAvailable\nFresh stored Feature + exact Permission binding")
box((45, 900, 800, 1000), "Native validator / controller / action\nOnly after both enabled restrictions pass")
box((865, 270, 1245, 1000), "SafeErrorResponder\n+ Common policy\n\nFeature OFF / unmet\nPREVIEW: generic 404\n\nConfiguration / storage /\nprovider faults: safe 500\n\nKnown auth refusal:\n401 + valid challenge\n\nSafeCodeIgniter catches\ndownstream ordinary\nloop faults before native\nraw logger / formatter\n\nOne diagnostic attempt,\nfinite JSON, fresh response", "#fff2df")
arrow((420, 210), (420, 270), "1. trusted selected target", (445, 223))
arrow((420, 370), (420, 430), "2. dispatch-owned selection", (445, 384))
arrow((420, 530), (420, 590), "3. resolve then admit (compressed)", (445, 543))
arrow((420, 680), (420, 740), "4. every effective Feature", (445, 694))
arrow((420, 835), (420, 900), "5. all pass, native filter returns null", (445, 853))
arrow((800, 320), (865, 320), "F", (820, 287))
draw.text((35, 1030), "Legend: dotted numbered arrows = runtime interactions, NOT static dependencies.", font=small, fill="#172b4d")
draw.text((35, 1064), "Scope: native ordinary before-filter path. Omits outer lifecycle, provider/storage internals, nested global loops.", font=small, fill="#172b4d")
image.save(Path(__file__).with_name("runtime-flow.png"))
