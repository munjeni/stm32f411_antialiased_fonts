### PHP based STM32 antialiased fonts and picture generator

This software allows you to convert TTF fonts to stm32 compatible fonts, generated fonts is antialiased and stm32 uses DMA with two buffers, ping-pong buffers so when dma proccess one buffer another bufer preparing font data, it is very fast. Php script is automated, you no need to create font or anything, just opet php script and modify parameters, run it and fonts is done! Fonts is with variable size, it will look beautifully on your display. Icons is RLE encoded and fonts is not since when RLE encoded it uses a much more space that raw so I added rle only to pictures and not for fonts.

### Usage

- font2.php - the script for generating fonts, see first few lines inside font2.php, edit it if you need. Its by dfault fonts 18px, Oswald Regular, you can change to whatever you need! Chose your TTF font, redefine what you need, and php script will generate it for you in just a seccond, you will see also preview of the generated font. Script will store all functions and definitios for your stm32 including fonts inside font_data.h header file.  When you run generated functions on your stm32 you can change color and backrground, it will be antialiased automaticalu with all colors you defined.

- icon2.php - the script to convert any picture to stm32 header file, similar to what does font2.php. When you run generated functions on your stm32 you can change color and backrground, it will be antialiased automaticalu with all colors you defined.

Enjoy!
