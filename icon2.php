<?php
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['font_image'])) {
    $uploadFile = $_FILES['font_image']['tmp_name'];
    $fontname = pathinfo($_FILES['font_image']['name'], PATHINFO_FILENAME);
    $fontname = str_replace(['-', ' '], '_', $fontname);

    // 1. UCITAVANJE SLIKE
    $imageInfo = getimagesize($uploadFile);
    if ($imageInfo === false) {
        die("Fajl nije slika!");
    }

    switch ($imageInfo[2]) {
        case IMAGETYPE_GIF: $tempIm = imagecreatefromgif($uploadFile); break;
        case IMAGETYPE_JPEG: $tempIm = imagecreatefromjpeg($uploadFile); break;
        case IMAGETYPE_PNG: $tempIm = imagecreatefrompng($uploadFile); break;
	case IMAGETYPE_BMP: $tempIm = imagecreatefrombmp($uploadFile); break;
        default: die("Format nije podrzan!");
    }

    $tempW = imagesx($tempIm);
    $tempH = imagesy($tempIm);

    // 2. SKENIRANJE: Trazimo "meso" (sadrzaj)
    $minY = $tempH;
    $maxY = 0;
    //for ($y = 0; $y < $tempH; $y++) {
    //    for ($x = 0; $x < $tempW; $x++) {
    //        $rgb = imagecolorat($tempIm, $x, $y);
    //        $r = ($rgb >> 16) & 0xFF;
    //        if ($r < 240) { // Ako nije belo
    //            if ($y < $minY) $minY = $y;
    //            if ($y > $maxY) $maxY = $y;
    //        }
    //    }
    //}
    
    $targetH = ($maxY >= $minY) ? ($maxY - $minY) + 1 : $tempH;
    $offY = ($maxY >= $minY) ? $minY : 0;

    // 3. PRIPREMA ZA GENERISANJE
    $finalW = $tempW;
    $loIm = imagecreatetruecolor($finalW, $targetH);
    imagecopy($loIm, $tempIm, 0, 0, 0, $offY, $finalW, $targetH);

    // Prikupi sve piksele u niz (invertovano)
    $raw_pixels = [];
    for ($y = 0; $y < $targetH; $y++) {
        for ($x = 0; $x < $finalW; $x++) {
            $val = (imagecolorat($loIm, $x, $y) >> 16) & 0xFF;
            $raw_pixels[] = 255 - $val;
        }
    }

    // --- RLE KOMPRESIJA (Tvoj format: 0x80|count za ponavljanje, 0x01 za unikate) ---
    $rle_output = [];
    $i = 0;
    $total = count($raw_pixels);

    while ($i < $total) {
        $run = 1;
        // Gledamo koliko se bajtova ponavlja (max 255)
        while ($i + $run < $total && $raw_pixels[$i] == $raw_pixels[$i + $run] && $run < 0xff) {
            $run++;
        }

	if ($run > 1) {
		$rle_output[] = $run;       // Cist broj, npr. 10 (0x0A)
		$rle_output[] = $raw_pixels[$i];
		$i += $run;
	} else {
		$rle_output[] = 1;          // Broj 1 za unikate
		$rle_output[] = $raw_pixels[$i];
		$i++;
	}
    }

    $metrics_c = "extern SPI_HandleTypeDef ST7789_SPI_PORT;\n\n";
    $metrics_c .= "#define " . strtoupper($fontname) . "_SPRITE_W " . $finalW . " // ukupna sirina\n";
    $metrics_c .= "#define " . strtoupper($fontname) . "_H " . $targetH . " // visina slike\n";
    $metrics_c .= "#define " . strtoupper($fontname) . "_MAX_W " . ($finalW + 1) . "\n\n";
    $metrics_c .= "typedef struct {\n	uint16_t x;\n	uint8_t w;\n} CharMap;\n\n";
    $metrics_c .= "const CharMap " . $fontname . "_metrics[] = {\n";
    $metrics_c .= "	{ 0, $finalW }\n};\n\n";

    $pixels_c = "const uint8_t " . $fontname . "_pixels[] = {\n\t";
    $total = count($rle_output);

    foreach ($rle_output as $idx => $byte) {
        $pixels_c .= sprintf("0x%02X", $byte);
    
        // Dodaj zarez i razmak ako nije poslednji element
        if ($idx < $total - 1) {
            $pixels_c .= ", ";
        
            // Novi red samo ako je deljivo sa 16 i NIJE kraj niza
            if (($idx + 1) % 16 == 0) {
                $pixels_c .= "\n\t";
            }
        }
    }
    $pixels_c .= "\n};\n";

    $functions_c = '
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
static uint16_t ' . $fontname . '_buffer1[' . strtoupper($fontname) . '_MAX_W * ' . strtoupper($fontname) . '_H];
static uint16_t ' . $fontname . '_buffer2[' . strtoupper($fontname) . '_MAX_W * ' . strtoupper($fontname) . '_H];
static uint8_t ' . $fontname . '_active_buf = 0;

//ST7789_PrintGraphic(0, 0, ' . $fontname . '_pixels, ' . strtoupper($fontname) . '_SPRITE_W, ' . strtoupper($fontname) . '_H, ' . $fontname . '_buffer1, ' . $fontname . '_buffer2, ' . $fontname . '_active_buf, 0xffff, 0x0000);

void ST7789_PrintGraphic(uint16_t x, uint16_t y, const uint8_t *graphic, uint16_t sprite_w, uint16_t img_h, uint16_t *aab1, uint16_t *aab2, uint8_t aab, uint16_t txtCol, uint16_t bgCol)
{
	// Priprema boja (Big-Endian za ST7789)
	uint16_t bgColBE = __REV16(bgCol); 
	uint16_t txtColBE = __REV16(txtCol); 

	// Cekaj DMA ako je zauzet
	while (HAL_DMA_GetState(ST7789_SPI_PORT.hdmatx) != HAL_DMA_STATE_READY);

	uint16_t *pBuf = (aab == 0) ? aab1 : aab2;

	// 1. RLE DEKOMPRESIJA DIREKTNO U BAFER
	uint32_t rle_ptr = 0;
	uint32_t buf_ptr = 0;
	uint32_t total_pixels = sprite_w * img_h;

	while (buf_ptr < total_pixels)
	{
		uint8_t count = graphic[rle_ptr++]; // Cita cist broj (npr. 10)
		uint8_t a     = graphic[rle_ptr++]; // Cita vrednost alfe

		for (uint16_t i = 0; i < count; i++)
		{
			// Sigurnosna provera
			if (buf_ptr < total_pixels)
			{
				if (a == 0) 
					pBuf[buf_ptr] = bgColBE;
				else if (a == 255) 
					pBuf[buf_ptr] = txtColBE;
				else 
					pBuf[buf_ptr] = __REV16(Blend565(txtCol, bgCol, a));

				buf_ptr++;
			}
		}
	}

	// 2. SLANJE NA EKRAN (225x225 prozor)
	ST7789_SetAddressWindow(x, y, x + sprite_w - 1, y + img_h - 1);
	HAL_GPIO_WritePin(ST7789_DC_PORT, ST7789_DC_PIN, GPIO_PIN_SET);
	HAL_GPIO_WritePin(ST7789_CS_PORT, ST7789_CS_PIN, GPIO_PIN_RESET);
	HAL_SPI_Transmit_DMA(&ST7789_SPI_PORT, (uint8_t *)pBuf, total_pixels * 2);

	// Zameni bafer za sledeci poziv
	aab = (aab == 0) ? 1 : 0;

	// Cekaj kraj slanja
	while (HAL_DMA_GetState(ST7789_SPI_PORT.hdmatx) != HAL_DMA_STATE_READY);
}
';

    file_put_contents('font_data.h', $metrics_c . $pixels_c . $functions_c);

    // Priprema preview-a
    ob_start();
    imagepng($loIm);
    $base64 = base64_encode(ob_get_clean());
    imagedestroy($tempIm);
    imagedestroy($loIm);
}
?>
<!DOCTYPE html>
<html lang="sr">
<head>
    <meta charset="UTF-8">
    <title>RLE Generator</title>
    <style>
        body { background: #cccccc; color: #000000; font-family: sans-serif; padding: 40px; }
        .box { background: #ffffff; padding: 20px; border-radius: 8px; border: 1px solid #444; }
        .preview-container { background: #cccccc; display: inline-block; line-height: 0; margin-top: 10px; padding: 5px; border: 1px solid #000; }
        input, button { padding: 10px; margin-top: 10px; cursor: pointer; }
    </style>
</head>
<body>
    <div class="box">
        <h3>Učitaj sliku za RLE generisanje</h3>
        <form method="POST" enctype="multipart/form-data">
            <input type="file" name="font_image" required><br>
            <button type="submit">Generiši font_data.h</button>
        </form>
    </div>

    <?php if (isset($base64)): ?>
    <div style="margin-top: 30px;">
        <h3>Rezultat (Visina: <?php echo $targetH; ?>px):</h3>
        <div class="preview-container">
            <img src="data:image/png;base64,<?php echo $base64; ?>" style="display:block;" />
        </div>
        <p>Fajl <b>font_data.h</b> je uspešno generisan sa RLE kompresijom.</p>
    </div>
    <?php endif; ?>
</body>
</html>
