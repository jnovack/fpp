-- Falcon Player: disable ALSA device reservation when running PipeWire system-wide
alsa_monitor = alsa_monitor or {}
alsa_monitor.properties = alsa_monitor.properties or {}
alsa_monitor.properties["alsa.reserve"] = false
