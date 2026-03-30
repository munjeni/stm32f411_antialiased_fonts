<?php

/*
MIT License

Copyright (c) 2026 munjeni

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
*/

//$fontPath = realpath('Play-Regular.ttf');
$fontPath = realpath('Oswald-Regular.ttf');
$fontinfo = pathinfo($fontPath);
$fontname =  basename($fontPath,'.'.$fontinfo['extension']);
$fontname = str_replace('-', '_', $fontname);
$targetH = 18;  // OVO CE BITI FINALNA VISINA NIZA
$renderSize = 40; // Renderujemo malo vece da bi imali sta da secemo

$firstChar = 32;
$lastChar = 126;

// 1. Renderujemo ceo set karaktera na privremeno platno da nadjemo "meso" (sadrzaj)
$text = "";
for ($i = $firstChar; $i <= $lastChar; $i++)
	$text .= chr($i);

$tempH = 100;
$tempW = 3000;
$tempIm = imagecreatetruecolor($tempW, $tempH);
$white = imagecolorallocate($tempIm, 255, 255, 255);
$black = imagecolorallocate($tempIm, 0, 0, 0);
imagefill($tempIm, 0, 0, $white); // Pozadina bela
imagefttext($tempIm, $renderSize, 0, 10, 70, $black, $fontPath, $text); // Tekst crn

// 2. SKENIRANJE: Trazimo crne piksele (gde je vrednost manja od 255)
$minY = $tempH;
$maxY = 0;

for ($y = 0; $y < $tempH; $y++)
{
	for ($x = 0; $x < $tempW; $x++)
	{
		$rgb = imagecolorat($tempIm, $x, $y);
		$r = ($rgb >> 16) & 0xFF;

		// Ako nije cisto belo, to je "meso" slova
		if ($r < 240)
		{
			if ($y < $minY)
				$minY = $y;

			if ($y > $maxY)
				$maxY = $y;
		}
	}
}

$contentH = ($maxY - $minY) + 1; // Stvarna visina slova bez praznina

// 3. GENERISANJE FINALNIH KARAKTERA (Force-fit na 18px)
$brojpixela = "brojpiksela";
$makssirina = "makssirina";
$makssirina2 = 0;
$metrics_c = 'extern SPI_HandleTypeDef ST7789_SPI_PORT;

#define SPRITE_W     ' . $brojpixela . '  // ukupna sirina RAW slike
#define FONT_H       ' . $targetH . '   // visina slike
#define MAX_CHAR_W   ' . $makssirina . '   // maksimalna sirina jednog slova za bafer

typedef struct {
	uint16_t x; // offset u RAW nizu
	uint8_t w;  // sirina slova
} CharMap;' . "\n\n";

$metrics_c .= "static const CharMap " . $fontname . "_metrics[] = {\n";
$pixels_c = "static const uint8_t " . $fontname . "_pixels[] = {\n";
$currentOffset = 0;
$glyphs = [];

// Prvo pripremamo sve glyphove u niz
for ($i = $firstChar; $i <= $lastChar; $i++)
{
	$char = chr($i);
	$box = imageftbbox($renderSize, 0, $fontPath, $char);
	$w = abs($box[2] - $box[0]);

	if ($w <= 0)
		$w = 15; // Space

	$charIm = imagecreatetruecolor($w + 10, $tempH);
	imagefill($charIm, 0, 0, $white);
	imagefttext($charIm, $renderSize, 0, 0, 70, $black, $fontPath, $char);

	$finalW = (int)round(($w / $contentH) * $targetH);

	if ($finalW <= 0)
		$finalW = 1;

	$loIm = imagecreatetruecolor($finalW, $targetH);
	imagefill($loIm, 0, 0, $white);
	imagecopyresampled($loIm, $charIm, 0, 0, 0, $minY, $finalW, $targetH, $w, $contentH);

	if ($i == $lastChar)
		$metrics_c .= "\t{ $currentOffset, $finalW }// ";
	else
		$metrics_c .= "\t{ $currentOffset, $finalW }, // ";

	if ($i == 32)
		$metrics_c .= "Space";
	else if ($i == 0x5c)
		$metrics_c .= "Backslash";
	else
		$metrics_c .= chr($i);

	$metrics_c .= "\n";

	if ($finalW > $makssirina2)
		$makssirina2 = $finalW;
    
	$glyphs[] = ['im' => $loIm, 'w' => $finalW];
	//$glyphs[] = ['im' => $loIm, 'w' => $finalW, 'char' => $char, 'hex' => sprintf("%02X", $i), 'h'=> $targetH];
	$currentOffset += $finalW; // m.x je sada X pozicija
}

$totalpiksels = $currentOffset; // SPRITE_W je suma sirina
$total_piksel_bytes = 0;

// Prikupi sve piksele u niz (invertovano)
//$raw_pixels = [];

// Generisanje piksela: Red po red za sva slova (Atlas format)
for ($y = 0; $y < $targetH; $y++)
{
	$pixels_c .= "	";

	foreach ($glyphs as $g)
	{
		for ($x = 0; $x < $g['w']; $x++)
		{
			$val = (imagecolorat($g['im'], $x, $y) >> 16) & 0xFF;
			$pixel = sprintf("0x%02X", 255 - $val);
			$pixels_c .= $pixel;

			//$raw_pixels[$total_piksel_bytes] = $pixel;

			if ($total_piksel_bytes < ($totalpiksels * $targetH) - 1)
				$pixels_c .= ", ";

			$total_piksel_bytes += 1;
		}
	}

	//$pixels_c .= "// Red $y\n";
	$pixels_c .= "\n";
}

// Generisanje piksela: Red po red za sva slova (Atlas format)
//for ($y = 0; $y < $targetH; $y++)
//{
//	$pixels_c .= "    // --- Red $y ---\n    ";
//
//	foreach ($glyphs as $index => $g)
//	{
//		for ($x = 0; $x < $g['w']; $x++)
//		{
//			$val = (imagecolorat($g['im'], $x, $y) >> 16) & 0xFF;
//			$pixels_c .= sprintf("0x%02X, ", 255 - $val);
//		}
//
//		$char = chr($index + $firstChar);
//		$displayChar = ($char === ' ') ? "Space" : $char;
//		$pixels_c .= " /* $displayChar */ "; // Komentar nakon svakog karaktera unutar reda
//	}
//
//	$pixels_c .= "\n\n"; // Prelazak u novi red nakon sto se obrade sva slova za tu visinu
//}

$metrics_c = str_replace('brojpiksela', $totalpiksels, $metrics_c);
$metrics_c = str_replace('makssirina', $makssirina2, $metrics_c);

$pixels_c .= '};

/* --- ALPHA BLENDING --- */
static inline uint16_t Blend565(uint16_t fore, uint16_t back, uint8_t alpha)
{
	if (alpha == 0)
		return back;

	if (alpha == 255)
		return fore;

	uint32_t a = (alpha + 4) >> 3; 
	uint32_t inv_a = 32 - a;
	uint32_t f_rb = (fore & 0xF81F) | ((uint32_t)(fore & 0x07E0) << 16);
	uint32_t b_rb = (back & 0xF81F) | ((uint32_t)(back & 0x07E0) << 16);
	uint32_t res_rb = ((f_rb * a) + (b_rb * inv_a)) >> 5;

	return (uint16_t)((res_rb & 0xF81F) | ((res_rb >> 16) & 0x07E0));
}

/* --- GLAVNA FUNKCIJA ZA ISPIS --- */
static uint16_t aa_buffer1[MAX_CHAR_W * FONT_H];
static uint16_t aa_buffer2[MAX_CHAR_W * FONT_H];
static uint8_t active_buf = 0;

void ST7789_PrintAA(uint16_t x, uint16_t y, char *str, uint16_t txtCol, uint16_t bgCol)
{
	uint16_t curX = x;

	// Hardware swap boja (ST7789 trazi Big-Endian)
	uint16_t bgColBE = __REV16(bgCol); 
	uint16_t txtColBE = __REV16(txtCol); 

	while (*str)
	{
		// Preskoci karaktere van opsega
		if (*str < 32 || *str > 126)
		{
			str++;
			continue;
		}

		// Uzmi metriku za trenutni karakter
		CharMap m = ' . $fontname . '_metrics[*str - 32];
		uint16_t charW = m.w; 

		// 1. KRITICNA OPTIMIZACIJA: Cekaj DMA samo ako je jos zauzet prethodnim slanjem.
		while (HAL_DMA_GetState(ST7789_SPI_PORT.hdmatx) != HAL_DMA_STATE_READY);

		// 2. Izbor slobodnog bafera (ping-pong princip)
		uint16_t *pBuf = (active_buf == 0) ? aa_buffer1 : aa_buffer2;

		// 3. Brzo popunjavanje bafera bojom pozadine DO FIKSNE SIRINE (boksa)
		// Ovo zamenjuje ST7789_DrawFilledRectangle i sprecava "duhove" starog fonta
		for (int i = 0; i < MAX_CHAR_W * FONT_H; i++)
		{
			pBuf[i] = bgColBE;
		}

		// 4. Rasterizacija karaktera (Anti-Aliasing) unutar bafera sirine boksa
		for (uint16_t r = 0; r < FONT_H; r++)
		{
			// Pozicioniraj se na pocetak reda u RAW tabeli piksela
			uint8_t *pixel_ptr = (uint8_t *)&' . $fontname . '_pixels[(r * SPRITE_W) + m.x];

			for (uint16_t c = 0; c < charW; c++)
			{
				// Provera da ne izadjemo van boks granice bafera
				if (c >= MAX_CHAR_W)
				{
					break;
				}

				uint8_t a = pixel_ptr[c]; // Vrednost providnosti (0-255)

				if (a == 0)
				{
					continue; // Piksel je providan, ostaje bgColBE
				}

				if (a == 255)
				{
					pBuf[r * MAX_CHAR_W + c] = txtColBE; // Potpuno neprovidan
				}
				else
				{
					// Blenduj boju teksta i pozadine na osnovu providnosti
					uint16_t color = Blend565(txtCol, bgCol, a);
					pBuf[r * MAX_CHAR_W + c] = __REV16(color); // Hardware swap rezultata
				}
			}
		}

		// 5. Priprema ekrana za prijem podataka (Adresni prozor fiksne sirine boksa)
		ST7789_SetAddressWindow(curX, y, curX + MAX_CHAR_W - 1, y + FONT_H - 1);

		HAL_GPIO_WritePin(ST7789_DC_PORT, ST7789_DC_PIN, GPIO_PIN_SET);
		HAL_GPIO_WritePin(ST7789_CS_PORT, ST7789_CS_PIN, GPIO_PIN_RESET);

		// 6. Pokretanje DMA prenosa (saljemo uvek fiksnu sirinu boksa)
		HAL_SPI_Transmit_DMA(&ST7789_SPI_PORT, (uint8_t *)pBuf, MAX_CHAR_W * FONT_H * 2);

		// cekaj kraj poslednjeg karaktera pre izlaska da bi sabirnica bila slobodna
		while (HAL_DMA_GetState(ST7789_SPI_PORT.hdmatx) != HAL_DMA_STATE_READY);
		HAL_GPIO_WritePin(ST7789_CS_PORT, ST7789_CS_PIN, GPIO_PIN_SET);

		// 7. Pomeri kursor, predji na sledeci karakter i zameni bafer
		// Pomeraj curX za charW zadrzava originalni "kerning" fonta
		curX += charW; 
		str++;
		active_buf = (active_buf == 0) ? 1 : 0; 
	}
}' . "\n";

// 4. DISPLAY & SAVE
$preview = imagecreatetruecolor($totalpiksels, $targetH);
$cx = 0;

foreach($glyphs as $g)
{
	imagecopy($preview, $g['im'], $cx, 0, 0, 0, $g['w'], $targetH);
	$cx += $g['w'];
}

file_put_contents('font_data.h', $metrics_c . "};\n\n" . $pixels_c);

ob_start();
imagepng($preview);
$base64 = base64_encode(ob_get_clean());

echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>FONT Generator</title>
    <style>
        body { background: #cccccc; color: #000000; font-family: sans-serif; padding: 40px; }
        .box { background: #ffffff; padding: 20px; border-radius: 8px; border: 1px solid #444; }
        .preview-container { background: #cccccc; display: inline-block; line-height: 0; margin-top: 10px; padding: 5px; border: 1px solid #000; }
        input, button { padding: 10px; margin-top: 10px; cursor: pointer; }
    </style>
</head>
';
echo "<body style='background:#2222; padding:50px;'>\n";
echo "<h3>Font 18px is generated to font_data.h! Inspect this picture of the font look:</h3>\n";
echo "<div style='background:white; display:inline-block; line-height:0;'><img src='data:image/png;base64,$base64' style='height:18px; display:block;' /></div>\n";
echo "</body>\n</html>\n";
