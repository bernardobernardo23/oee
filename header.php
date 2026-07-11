<header class="bg-white border-b border-gray-200 shadow-sm px-4 md:px-8 py-3 flex justify-between items-center sticky top-0 z-50 font-sans">
    
    <div class="flex items-center gap-3 md:gap-5">
        
        <button onclick="window.history.back()" title="Voltar para a página anterior" class="group flex items-center justify-center w-10 h-10 rounded-full bg-gray-50 hover:bg-gray-100 border border-transparent hover:border-gray-300 transition-all duration-300 shadow-sm hover:shadow shrink-0">
            <svg class="w-5 h-5 text-gray-400 group-hover:text-gray-800 transition-colors" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
        </button>

        <div class="w-10 h-10 md:w-12 md:h-12 bg-gray-50 rounded-lg flex items-center justify-center overflow-hidden border border-gray-100 shrink-0 shadow-inner">
            <img src="logo.png" alt="Logo ChesiQuímica" class="w-full h-full object-contain p-1.5">
        </div>
        
        <div class="h-8 w-[2px] bg-gray-200 hidden md:block rounded-full"></div>
        
        <div class="flex flex-col justify-center">
            <h1 class="text-lg md:text-xl font-black text-gray-900 tracking-tighter uppercase leading-none mb-1">
                ChesiQuímica
            </h1>
            
            <div class="flex items-center gap-2">
                <?php if (isset($_SESSION['login']) && isset($_SESSION['fabrica']) && $_SESSION['fabrica'] > 0 && $_SESSION['fabrica'] != 99): ?>
                    <span class="text-[10px] md:text-xs font-bold text-gray-500 uppercase tracking-widest flex items-center gap-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                        Linha: <span class="text-blue-600"><?= htmlspecialchars($_SESSION['login']) ?></span>
                    </span>
                <?php endif; ?>

                <?php if (isset($_SESSION['fabrica']) && $_SESSION['fabrica'] == 99): ?>
                    <span class="text-[10px] md:text-xs font-bold text-pink-600 uppercase tracking-widest flex items-center gap-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-pink-500"></span>
                        PCP
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="flex items-center gap-4 md:gap-6">
        
        <a href="logout.php" title="Sair do Sistema" class="group flex items-center justify-center w-10 h-10 rounded-full bg-gray-50 hover:bg-red-50 border border-transparent hover:border-red-200 transition-all duration-300 shadow-sm hover:shadow">
            <svg class="w-5 h-5 text-gray-400 group-hover:text-red-600 transition-colors" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
            </svg>
        </a>
    </div>
    
</header>