<?php

namespace Innova\PathBundle\Controller;

use Doctrine\Common\Collections\ArrayCollection;

use Symfony\Component\HttpFoundation\Response; 
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request; 
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\SecurityContextInterface;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration as EXT;

use Innova\PathBundle\Entity\Path;
use Innova\PathBundle\Entity\Step;
use Innova\PathBundle\Entity\Resource;
use Innova\PathBundle\Entity\StepType;
use Innova\PathBundle\Entity\StepWho;
use Innova\PathBundle\Entity\StepWhere;
use Innova\PathBundle\Entity\Step2ResourceNode;
use Innova\PathBundle\Entity\Step2Resource;

use Claroline\CoreBundle\Entity\Resource\Activity;
use Claroline\CoreBundle\Entity\Resource\ResourceNode;
use Claroline\CoreBundle\Entity\Resource\ResourceType;
use Claroline\CoreBundle\Entity\Resource\ResourceActivity;
use Claroline\CoreBundle\Entity\Resource\ResourceRights;

class PathController extends Controller 
{
    /**
     * @Route(
     *     "/",
     *     name = "innova_path_from_desktop",
     *     options = {"expose"=true}
     * )
     *
     * @Template("InnovaPathBundle::path_desktop.html.twig")
     */
    public function fromDesktopAction()
    {
        return array();
    }

    /**
     * @Route(
     *     "/innova_path_deploy",
     *     name = "innova_path_deploy"
     * )
     * @Method("POST")
     * @Template("InnovaPathBundle::path_workspace.html.twig")
     */
    public function deployAction()
    {
        $manager = $this->entityManager();

        // Récupération vars HTTP
        $pathId = $this->get('request')->request->get('path-id');
        $path = $manager->getRepository('InnovaPathBundle:Path')->findOneById($pathId);
        
        // JSON string to Object - Récupération des childrens de la racine
        $json = json_decode($path->getPath());
        $json_root_steps = $json->steps;

        // Récupération Workspace courant et la resource root
        $workspaceId = $this->get('request')->request->get('workspace-id');
        $workspace = $manager->getRepository('ClarolineCoreBundle:Workspace\AbstractWorkspace')->findOneById($workspaceId);
        $root = $manager->getRepository('ClarolineCoreBundle:Resource\ResourceNode')->findWorkspaceRoot($workspace);

        // Récupération utilisateur courant.
        $user = $this->get('security.context')->getToken()->getUser();

        // création du dossier _paths s'il existe pas.
        if(!$pathsDirectory = $manager->getRepository('ClarolineCoreBundle:Resource\ResourceNode')->findOneByName("_paths")){
            $pathsDirectory = new ResourceNode();
            $pathsDirectory->setName("_paths");
            $pathsDirectory->setClass("Claroline\CoreBundle\Entity\Resource\Directory");
            $pathsDirectory->setCreator($user);
            $pathsDirectory->setResourceType($manager->getRepository('ClarolineCoreBundle:Resource\ResourceType')->findOneById(2));
            $pathsDirectory->setWorkspace($workspace);
            $pathsDirectory->setParent($root);
            $pathsDirectory->setMimeType("custom/directory");
            $pathsDirectory->setIcon($manager->getRepository('ClarolineCoreBundle:Resource\ResourceIcon')->findOneById(7));

            $manager->persist($pathsDirectory);
            $manager->flush();
        }
       

        //lancement récursion 
        $this->JSONParser($json_root_steps, $user, $workspace, $pathsDirectory, null, 0, $path);

        return array('workspace' => $workspace, 'ok' => "Parcours déployé.");
    }

    private function JSONParser($steps, $user, $workspace, $pathsDirectory, $parent, $order, $path)
    {
        $manager = $this->entityManager();
        $rm = $this->resourceManager();

        foreach ($steps as $step) {
            $order++;

            // STEP MANAGEMENT
            $resourceNode = new ResourceNode();
            $resourceNode->setName($step->name);
            $resourceNode->setClass("Innova\PathBundle\Entity\Step");
            $resourceNode->setCreator($user);
            $resourceNode->setResourceType($manager->getRepository('ClarolineCoreBundle:Resource\ResourceType')->findOneById(10));
            $resourceNode->setWorkspace($workspace);
            $resourceNode->setParent($pathsDirectory);
            $resourceNode->setMimeType("custom/activity");
            $resourceNode->setIcon($manager->getRepository('ClarolineCoreBundle:Resource\ResourceIcon')->findOneById(35));

            $manager->persist($resourceNode);
            $manager->flush();

            $rm->setLastPosition($pathsDirectory, $resourceNode);
            
            // Création Step
            $step1 = new Step();
            $step1->setResourceNode($resourceNode);
            $step1->setUuid($step->id);
            $step1->setParent($parent);
            $step1->setStepOrder($order);
            $stepType = $manager->getRepository('InnovaPathBundle:StepType')->findOneById($step->type);
            $step1->setStepType($stepType);
            $stepWho = $manager->getRepository('InnovaPathBundle:StepWho')->findOneById($step->who);
            $step1->setStepWho($stepWho);
            $stepWhere = $manager->getRepository('InnovaPathBundle:StepWhere')->findOneById($step->where);
            $step1->setStepWhere($stepWhere); 
            $step1->setDuration(new \DateTime());
            $step1->setExpanded($step->expanded);
            $step1->setWithTutor($step->withTutor);
            $step1->setWithComputer($step->withComputer);
            $step1->setInstructions($step->instructions);
            $step1->setPath($path);
            $manager->persist($step1);
           

            // RESOURCES MANAGEMENT
            $resourceOrder = 0;
            foreach ($resources as $resource) {
                $resourceOrder++;

                $resourceNodeId = $resource->resourceId;
                $step2ressourceNode = new Step2ResourceNode();
                $resourceNode = $manager->getRepository('ClarolineCoreBundle:Resource\ResourceNode')->findOneById($resourceNodeId);
                $step2ressourceNode->setResourceNode($resourceNode);
                $step2ressourceNode->setStep($step1);
                $step2ressourceNode->setResourceOrder($resourceOrder);

                $manager->persist($step2ressourceNode);
            }
            $manager->flush();

            /*
            // Gestion de la jointure ResourceActivity - Ne sert plus à rien je crois
            $resourceActivity = new ResourceActivity();
            $resourceActivity->setActivity($activity);
            $resourceActivity->setResourceNode($resourceNode);
            $resourceActivities = $manager->getRepository('ClarolineCoreBundle:Resource\ResourceActivity')
                ->findByActivity($activity->getId());
            $count = count($resourceActivities)+1; // TODO: A revoir
            $resourceActivity->setSequenceOrder($count);

            $manager->persist($resourceActivity);
            $manager->flush();  
            */

            // Gestion des droits.
            $right1 = new ResourceRights();
            $right1->setRole($manager->getRepository('ClarolineCoreBundle:Role')->findOneById(3));
            $right1->setResourceNode($resourceNode);
            $manager->persist($right1);

            $manager->flush(); 

            // récursivité sur les enfants possibles.
            $this->JSONParser($step->children, $user, $workspace, $pathsDirectory, $step->id, 0, $path);
        }

        $manager->flush();     
    }

    /**
     * @Route(
     *     "/",
     *     name = "innova_path_from_workspace",
     *     options = {"expose"=true}
     * )
     *
     * @Template("InnovaPathBundle::path_workspace.html.twig")
     *
     */
    public function fromWorkspaceAction()
    {
        $manager = $this->container->get('doctrine.orm.entity_manager');
       
        $id = $this->get('request')->query->get('id');

        $workspace = $manager->getRepository('ClarolineCoreBundle:Workspace\AbstractWorkspace')->find($id);
        
        $resourceType = $manager->getRepository('ClarolineCoreBundle:Resource\ResourceType')->findOneByName('path');

        $paths = $manager->getRepository('ClarolineCoreBundle:Resource\ResourceNode')->findByWorkspaceAndResourceType($workspace, $resourceType);

        return array('workspace' => $workspace, 'paths' => $paths);
    }

    /**
     * @Route(
     *     "/paths",
     *     name = "innova_path_get_paths",
     *     options = {"expose"=true}
     * )
     *
     * @Method("GET")
     *
     */
    public function getPathsAction()
    {
        $em = $this->entityManager();
        
        $results = $em->getRepository('InnovaPathBundle:Path')->findAll();

        $paths = array();

        foreach ($results as $result) {
            $path = new \stdClass();
            $path->id = $result->getId();
            $path->path = json_decode($result->getPath());

            $paths[] = $path;
        }

        return new JsonResponse($paths);
    }

    /**
     * @Route(
     *     "/path/{id}",
     *     name = "innova_path_get_path",
     *     options = {"expose"=true}
     * )
     *
     * @Method("GET")
     *
     */
    public function getPathAction(Path $path)
    {
        $newPath = json_decode($path->getPath());
        $newPath->id = $path->getId();
    
        return new JsonResponse($newPath);
    }

    /**
    * @Route(
    *     "/path/add",
    *     name = "innova_path_add_path",
    *     options = {"expose"=true}
    * )
    * @Method("POST")
    *
    */
    public function addPathAction()
    {

        $em = $this->entityManager();

        $editDate = new \DateTime();
        $user = "Arnaud";
        $content = $this->get('request')->getContent();
        
        $new_path = New Path;
        $new_path->setUser($user)
                 ->setEditDate($editDate)
                 ->setPath($content);

        $em->persist($new_path);
        $em->flush();

        return New Response(
            $new_path->getId()
        );
    }

    /**
    * @Route(
    *     "/path/edit/{id}",
    *     name = "innova_path_edit_path",
    *     options = {"expose"=true}
    * )
    * @Method("PUT")
    *
    */
    public function editPathAction(Path $path)
    {
        $em = $this->entityManager();

        $editDate = new \DateTime();
        $content = $this->get('request')->getContent();

        $path->setEditDate($editDate)
             ->setPath($content);

        $em->persist($path);
        $em->flush();

        return New Response(
            $path->getId()
        );
    }

    /**
    * @Route(
    *     "/path/delete/{id}",
    *     name = "innova_path_delete_path",
    *     options = {"expose"=true}
    * )
    * @Method("DELETE")
    *
    */
    public function deletePathAction(Path $path)
    {
        $em = $this->entityManager();

        $em->remove($path);
        $em->flush();

        return New Response("ok");
    }

    public function entityManager()
    {
        $em = $this->get('doctrine.orm.entity_manager');
        $em = $this->getDoctrine()->getManager();
        
        return $em;
    }

    public function resourceManager()
    {
        $rm = $this->get('claroline.manager.resource_manager');
        //$rm = $this->getDoctrine()->getManager();
        
        return $rm;
    }

}
     

     
