<?php

namespace App\Controller;

use App\Class\NumberToStr;
use App\Entity\Client;
use App\Entity\ClientHistory;
use App\Entity\Facture;
use App\Form\BillType;
use App\Form\ClientType;
use App\Repository\ClientHistoryRepository;
use App\Repository\ClientRepository;
use App\Repository\CommandeRepository;
use App\Repository\FactureRepository;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Bundle\SnappyBundle\Snappy\Response\PdfResponse;
use Knp\Component\Pager\PaginatorInterface;
use Knp\Snappy\Pdf;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Constraints\Length;

class ClientController extends AbstractController
{   
    #[Route('/', name: 'home')]
    public function home()
    {
        return $this->render('accueil/home.html.twig');
    }
    /**
     * set allowed method as POST/GET
     * 
     * We apply this method for adding a new client into our database
     * 
     * @return a Response object
    */
    #[Route('/client/add', name: 'client_create', methods: ['GET', 'POST'])]
    public function create(ClientHistoryRepository $rep, Request $req, EntityManagerInterface $em): Response
    {
        $client = new Client;
        $history = new ClientHistory;

        $numb = $rep->findAll();

        #filter its identifier so we can get the number after 'CL' 
        if(count($numb) === 0){
            $client->setNumcli('CL01');
        }else{        
            $client->setNumcli(count($numb)<9? 'CL0'.count($numb)+1:'CL'.count($numb)+1);
        }

        $form = $this->createForm(ClientType::class, $client);

        $form->handleRequest($req);

        if($form->isSubmitted() && $form->isValid()){
            $history->setNom($client->getNom());

            $em->persist($history);
            $em->persist($client);
            $em->flush();

            $this->addFlash('success', 'Client ajouté avec succès!');

            return $this->redirectToRoute('client_list');
        }
        return $this->render('client/create.html.twig', [
            'form' => $form->createView()
        ]);
    }

    /**
     * set allowed method as PATCH/GET
     * 
     * @return a Response object
    */
    #[Route('/client/modify/{id}', name: 'client_edit', methods: ['GET', 'PATCH'])]
    public function modify(Client $client, Request $req, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(ClientType::class, $client, [
            'method' => 'PATCH'
        ]);

        $form->handleRequest($req);

        if($form->isSubmitted() && $form->isValid()){
            $em->flush();

            $this->addFlash('success', 'Client '.$client->getNumcli().' modifié avec succès');

            return $this->redirectToRoute('client_list');
        }

        return $this->render('client/modify.html.twig', [
            'form' => $form->createView(),
            'client' => $client
        ]);
    }

    /**
     * render all clients from the database
     * 
     * @return a Response Object
    */
    #[Route('/client', name: 'client_list', methods: ['GET'])]
    public function list(ClientRepository $rep, PaginatorInterface $paginator, Request $req): Response
    {
        $clients = $rep->findAll();

        $pagination = $paginator->paginate(
            $clients,
            $req->query->getInt('page', 1),
            10
        );

        # use GET method
        # disable csrf protection
        $form = $this->createFormBuilder(null, ['method' => 'GET', 'csrf_protection' => false])
            ->add('search', TextType::class, [
                'label' => ' ',
                'attr' => [
                    'placeholder' => "Tapez le nom ou l'identifiant du client",
                ],
                'required' => false
            ])
            ->getForm();
        
        $form->handleRequest($req);

        if($form->isSubmitted() && $form->isValid()){
            $specificClients = $rep->search($form->getData()['search']);
            $pagination = $paginator->paginate(
                $specificClients,
                $req->query->getInt('page', 1),
                10
            );

            if($form->getData()['search'] !== null)
                return $this->render('client/list.html.twig', [
                    'pagination' => $pagination,
                    'form' => $form->createView()
                ]); 
        }
        

        return $this->render('client/list.html.twig', [
            'pagination' => $pagination,
            'form' => $form->createView()
        ]);
    }

    /**
     * set allowed method as DELETE.
     * 
     * DELETE is among the HTTP method which is used for deletion
     * 
     * @return a Response object
    */
    #[Route('/client/delete/{id}', name: 'client_delete', methods: ['DELETE'])]
    public function delete(Client $client, Request $req, EntityManagerInterface $em): Response
    {
        //csrf protection
        if($this->isCsrfTokenValid('client_deletion_'.$client->getNumcli(), $req->request->get('csrf_token'))){
            $em->remove($client);

            $this->addFlash('success', 'Client '.$client->getNumcli().' supprimé avec succès');
            $em->flush();
        }

        return $this->redirectToRoute('client_list');
    }
    
    #[Route('/client/productList/{id}', name: 'client_product_list', methods: ['GET'])]
    public function show(Client $client, CommandeRepository $rep, Request $req): Response
    {
        $commandListPerClient = $rep->findBy(['clients' => $client]);

        # use GET method
        # disable csrf protection
        $form = $this->createFormBuilder(null, ['method' => 'GET', 'csrf_protection' => false])
            ->add('beginningDate', DateType::class, [
                'widget' => 'single_text',
                'required' => false,
                'attr' => [
                    'title' => 'Début'
                ]
            ])
            ->add('lastDate', DateType::class, [
                'widget' => 'single_text',
                'required' => false,
                'attr' => [
                    'title' => 'Fin'
                ]
            ])
            ->getForm()
        ;

        # set the current time as the default value of the lastDate field
        $form->get('lastDate')->setData(new DateTime);
        
        $form->handleRequest($req);

        if($form->isSubmitted() && $form->isValid() && 
            $form->getData()['beginningDate'] !== null && $form->getData()['lastDate'] !== null){
                $commands = $rep->findAllCommandBetweenTwoDates($form->getData()['beginningDate'], $form->getData()['lastDate']);
                return $this->render('client/show.html.twig',[
                    'client' => $client,
                    'form' => $form->createView(),
                    'commands' => $commands
                ]);    
        }

        return $this->render('client/show.html.twig',[
            'client' => $client,
            'form' => $form->createView(),
            'commands' => $commandListPerClient
        ]);
    }

    #[Route(path: '/client/turnover', name: 'clients_turnover', methods: ['GET'])]
    public function turnOver(CommandeRepository $rep, Request $req, PaginatorInterface $paginator): Response
    {
        #create form to filter data
        $form = $this->createFormBuilder(null, ['method' => 'GET', 'csrf_protection' => false])
            ->add('year', NumberType::class, [
                'required' => false,
                'attr' => [
                    'placeholder' => "Tapez une année",
                    'min' => 1,
                    'max' => date('Y')
                ],
                'constraints' => [
                    new Length([
                        'min' => 1,
                        'minMessage' => 'Le champ devra contenir au moins {{ min }} caractère',
                        'max' => 4,
                        'maxMessage' => 'Le champ devra contenir au plus {{ max }} caractère'
                    ]),
                ]
            ])
            ->getForm()
        ;
        $clients = $rep->findAllTurnOversPerClient();
        
        #set the current year as default value
        $form->get('year')->setData(date('Y'));
        $form->handleRequest($req);

        if($form->isSubmitted() && $form->isValid() && $form->getData()['year'] !== null){
            $clients = $rep->findAllTurnOversPerClient($form->getData()['year']);

            $pagination = $paginator->paginate(
                $clients,
                $req->query->getInt('page', 1),
                10
            );

            if($clients)
                return $this->render('client/turnOver.html.twig', [
                    'pagination' => $pagination,
                    'form' => $form->createView(),
                    'year' => $form->get('year')->getData() 
                ]);
        }

        $pagination = $paginator->paginate(
            $clients,
            $req->query->getInt('page', 1),
            10
        );
        
        return $this->render('client/turnOver.html.twig', [
            'pagination' => $pagination,
            'form' => $form->createView(),
            'year' => date('Y') 
        ]);
    }

    #[Route(path: '/bill/generate', name: 'generate_bill', methods: ['GET', 'POST'])]
    public function factureGenerate(Request $req, EntityManagerInterface $em,FactureRepository $factRep, 
        CommandeRepository $rep,Pdf $knpSnappyPdf): Response
    {
        $facture = new Facture;
        $facture->setDateFacture(new DateTimeImmutable);

        $form = $this->createForm(BillType::class, $facture);
        $form->handleRequest($req);

        if($form->isSubmitted() && $form->isValid()){
            $facture->setId(count($factRep->findAll())+1);

            #get commands made by a client in one purchase
            $commandes = $rep->findBy([
                'clients' => $form->getData()->getClient(),
                'date_commande' => $form->getData()->getDateFacture()
            ]);
            
            #compute the total costs
            $total = 0;
            foreach($commandes as $commande){
                $total += $commande->getProduits()->getPu()*$commande->getQte();
            }

            #store the bill view into a variable
            $html = $this->renderView('facture/facture.html.twig', [
                'facture' => $facture,
                'commands' => $commandes,
                'total' => $total,
                'totalToString' => (new NumberToStr())->intToStr($total)
            ]);

            $em->persist($facture);
            $em->flush();

            #render the bill
            $knpSnappyPdf->setOption("enable-local-file-access",true);
            return new PdfResponse(
                $knpSnappyPdf->getOutputFromHtml($html),
                'bill.pdf'
            );
        }

        return $this->render('facture/FactureGenerate.html.twig',[
            'form' => $form->createView()
        ]);

    }
}
