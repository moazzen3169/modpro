$urls = @(
    "https://fonts.gstatic.com/s/vazirmatn/v16/Dxx78j6PP2D_kU2muijPEe1n2vVbfJRklWgzORc.ttf",
    "https://fonts.gstatic.com/s/vazirmatn/v16/Dxx78j6PP2D_kU2muijPEe1n2vVbfJRklVozORc.ttf",
    "https://fonts.gstatic.com/s/vazirmatn/v16/Dxx78j6PP2D_kU2muijPEe1n2vVbfJRklbY0ORc.ttf",
    "https://fonts.gstatic.com/s/vazirmatn/v16/Dxx78j6PP2D_kU2muijPEe1n2vVbfJRklY80ORc.ttf",
    "https://fonts.gstatic.com/s/vazirmatn/v16/Dxx78j6PP2D_kU2muijPEe1n2vVbfJRkleg0ORc.ttf",
    "https://fonts.gstatic.com/s/vazirmatn/v16/Dxx78j6PP2D_kU2muijPEe1n2vVbfJRklcE0ORc.ttf"
)

$filenames = @(
    "Dxx78j6PP2D_kU2muijPEe1n2vVbfJRklWgzORc.ttf",
    "Dxx78j6PP2D_kU2muijPEe1n2vVbfJRklVozORc.ttf",
    "Dxx78j6PP2D_kU2muijPEe1n2vVbfJRklbY0ORc.ttf",
    "Dxx78j6PP2D_kU2muijPEe1n2vVbfJRklY80ORc.ttf",
    "Dxx78j6PP2D_kU2muijPEe1n2vVbfJRkleg0ORc.ttf",
    "Dxx78j6PP2D_kU2muijPEe1n2vVbfJRklcE0ORc.ttf"
)

for ($i = 0; $i -lt $urls.Length; $i++) {
    Invoke-WebRequest -Uri $urls[$i] -OutFile "libs/fonts/$($filenames[$i])"
}
