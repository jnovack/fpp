ifeq '$(ARCH)' 'Raspberry Pi'
CFLAGS += \
	-DPLATFORM_PI

# PLATFORM_PI is set on both 32- and 64-bit Pi builds; PLATFORM_PI64 is set
# only on aarch64 so plugins can opt into 64-bit-only paths without breaking
# code that already checks PLATFORM_PI. Mirrors PLATFORM_BBB / PLATFORM_BB64.
ifeq ($(shell uname -m),aarch64)
CFLAGS += -DPLATFORM_PI64
endif

SUBMODULES += \
	external/RF24 \
	external/rpi-rgb-led-matrix \
	external/rpi_ws281x \
	external/spixels

OBJECTS_GPIO_ADDITIONS+=
LIBS_GPIO_ADDITIONS+=-lgpiod -lgpiodcxx


OBJECTS_fppoled += util/SPIUtils.o

BUILD_FPPOLED=1
BUILD_FPPCAPEDETECT=1
BUILD_FPPRTC=1
BUILD_FPPINIT=1

LDFLAGS+=-lrt -lpthread
SHLIB_EXT=so

LIBS_GPIO_EXE_ADDITIONS+=$(LIBS_GPIO_ADDITIONS) -lfpp-pi-gpio
DEPENDENCIES_GPIO_ADDITIONS+=libfpp-pi-gpio.$(SHLIB_EXT)

LIBS_fpp_so_EXTRA += -L. -lfpp_capeutils
DEPS_fpp_so += libfpp_capeutils.$(SHLIB_EXT)

endif
